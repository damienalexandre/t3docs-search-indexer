<?php

namespace App\Repository;

use App\Dto\Constraints;
use App\Dto\Manual;
use App\Dto\SearchDemand;
use App\QueryBuilder\ElasticQueryBuilder;
use Elastica\Aggregation\Terms;
use Elastica\Client;
use Elastica\Exception\InvalidException;
use Elastica\Index;
use Elastica\Multi\Search as MultiSearch;
use Elastica\Query;
use Elastica\Result;
use Elastica\Script\AbstractScript;
use Elastica\Script\Script;
use Elastica\Search;
use Elastica\Util;

use function Symfony\Component\String\u;
use T3Docs\VersionHandling\Typo3VersionMapping;

class ElasticRepository
{
    private const ELASTICA_DEFAULT_CONFIGURATION = [
        'host' => 'localhost',
        'port' => '9200',
        'path' => '',
        'transport' => 'Http',
        'index' => 'docsearch',
        'username' => '',
        'password' => '',
    ];

    private readonly Index $elasticIndex;

    private int $perPage = 10;

    private int $totalHits = 0;

    private readonly Client $elasticClient;

    public function __construct(private readonly ElasticQueryBuilder $elasticQueryBuilder)
    {
        $elasticConfig = $this->getElasticSearchConfig();

        if (!empty($elasticConfig['username']) && !empty($elasticConfig['password'])) {
            $elasticConfig['headers'] = [
                'Authorization' => 'Basic ' .
                    base64_encode($elasticConfig['username'] . ':' . $elasticConfig['password']) . '==',
            ];
        }

        $this->elasticClient = new Client($elasticConfig);
        $this->elasticIndex = $this->elasticClient->getIndex($elasticConfig['index']);
    }

    /**
     * @return Client
     */
    public function getElasticClient(): Client
    {
        return $this->elasticClient;
    }

    /**
     * @return Index
     */
    public function getElasticIndex(): Index
    {
        return $this->elasticIndex;
    }

    public function addOrUpdateDocument(array $snippet): void
    {
        // Generate id, without document version (snippet can be reused between versions)
        $urlFragment = str_replace('/', '-', $snippet['manual_title'] . '-' . $snippet['relative_url'] . '-' . $snippet['content_hash']);
        $documentId = $urlFragment . '-' . $snippet['fragment'];

        $scriptCode = <<<EOD
if (!ctx._source.manual_version.contains(params.manual_version)) {
    ctx._source.manual_version.add(params.manual_version);
}
if (!ctx._source.major_versions.contains(params.major_version)) {
    ctx._source.major_versions.add(params.major_version);
}
EOD;
        $version = $snippet['manual_version'];
        $majorVersion = explode('.', $version)[0];

        $script = new Script($scriptCode);
        $script->setParam('manual_version', $version);
        $script->setParam('major_version', $majorVersion);
        $snippet['manual_version'] = [$version];
        $snippet['major_versions'] = [$majorVersion];

        $script->setUpsert($snippet);
        $this->elasticIndex->getClient()->updateDocument($documentId, $script, $this->elasticIndex->getName());
    }

    /**
     * Removes manual_version from all snippets and if it's the last version, remove the whole snippet
     */
    public function deleteByManual(Manual $manual): void
    {
        $query = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'term' => [
                                'manual_title.raw' => $manual->getTitle(),
                            ],
                        ],
                        [
                            'term' => [
                                'manual_version' => $manual->getVersion(),
                            ],
                        ],
                        [
                            'term' => [
                                'manual_type' => $manual->getType(),
                            ],
                        ],
                        [
                            'term' => [
                                'manual_language' => $manual->getLanguage(),
                            ],
                        ],
                    ],
                ],
            ],
            'source' => $this->getDeleteQueryScript()
        ];
        $deleteQuery = new Query($query);
        $script = new Script($this->getDeleteQueryScript(), ['manual_version' => $manual->getVersion()], AbstractScript::LANG_PAINLESS);
        $this->elasticIndex->updateByQuery($deleteQuery, $script);
    }

    /**
     * @return int Number of deleted documents
     */
    public function deleteByConstraints(Constraints $constraints): int
    {
        $query = $this->elasticQueryBuilder->buildQuery($constraints);

        // If a specific manual version is provided, the goal is to remove only this version from
        // all associated snippets. In such cases, an update query is used instead of delete.
        // This approach ensures that if a snippet has no other versions remaining after the
        // removal of the specified one, the entire snippet is deleted. This deletion is
        // accomplished by setting ctx.op to "delete" in the provided script.
        if ($constraints->getVersion()) {
            $script = new Script($this->getDeleteQueryScript(), ['manual_version' => $constraints->getVersion()], AbstractScript::LANG_PAINLESS);
            $response = $this->elasticIndex->updateByQuery($query, $script, ['wait_for_completion' => true]);
        } else {
            $response = $this->elasticIndex->deleteByQuery($query, ['wait_for_completion' => true]);
        }

        return $response->getData()['total'];
    }

    /**
     * Provide elasticsearch script which removes version (provided in params) from a snippet
     * and if this is the last version assigned to snippet, it deletes the snippet from index (by setting ctx.op).
     *
     * @return string
     */
    protected function getDeleteQueryScript(): string
    {
        $script = <<<EOD
if (ctx._source.manual_version.contains(params.manual_version)) {
    for (int i=ctx._source.manual_version.length-1; i>=0; i--) {
        if (ctx._source.manual_version[i] == params.manual_version) {
            ctx._source.manual_version.remove(i);
        }
    }
}

def majorVersionParam = params.manual_version.splitOnToken('.')[0];
def hasOtherWithSameMajorVersion = false;
for (def version : ctx._source.manual_version) {
    def majorVersion = version.splitOnToken('.')[0];
    if (majorVersion.equals(majorVersionParam)) {
        hasOtherWithSameMajorVersion = true;
        break;
    }
}
if (!hasOtherWithSameMajorVersion && ctx._source.major_versions.contains(majorVersionParam)) {
    ctx._source.major_versions.remove(ctx._source.major_versions.indexOf(majorVersionParam));
}

if (ctx._source.manual_version.size() == 0) {
    ctx.op = "delete";
}
EOD;
        return \str_replace("\n", ' ', $script);
    }

    public function suggestScopes(SearchDemand $searchDemand): array
    {
        $suggestions = [];
        $searchTerms = trim(Util::escapeTerm($searchDemand->getQuery()));

        if ($searchTerms === '') {
            return [];
        }

        $limitingScopes = [
            'manual_vendor' => [
                'removeIfField' => 'manual_package'
            ],
            'manual_package' => [
                'addTopHits' => true,
            ],
            'option' => [],
            'manual_version' => [
                'field' => 'major_versions'
            ]
        ];

        $multiSearch = new MultiSearch($this->elasticClient);

        foreach ($limitingScopes as $scope => $settings) {
            $searchValue = $searchDemand->getFilters()[$scope] ?? null;
            $search = $searchTerms;

            $removeFromSuggestions = ($searchDemand->getFilters()[$settings['removeIfField'] ?? ''] ?? null) !== null;

            if ($searchValue !== null || $removeFromSuggestions) {
                continue;
            }

            $singleQuery = [
                'query' => [
                    'bool' => [
                        'must' => [
                            'multi_match' => [
                                'query' => $search,
                                'fields' =>
                                    [
                                        $settings['field'] ?? $scope,
                                        $scope,
                                        $scope . '.small_suggest',
                                        $scope . '.large_suggest',
                                    ],
                                'operator' => 'AND',
                            ],
                        ],
                    ],
                ],
                'highlight' => [
                    'fields' => [
                        $scope . '.small_suggest' => (object)[],
                        $scope . '.large_suggest' => (object)[],
                    ],
                ],
                'aggs' => [
                    $scope => [
                        'terms' => [
                            'field' => $settings['field'] ?? $scope,
                            'size' => 5
                        ],
                    ],
                ],
                '_source' => false,
                'size' => 0
            ];

            if ($settings['addTopHits'] ?? false) {
                $singleQuery['aggs'][$scope]['aggs']['manual_slug_hits'] = [
                    'top_hits' => [
                        'size' => 1,
                        '_source' => ['manual_version', 'manual_slug']
                    ]
                ];
            }

            $searchObj = new Search($this->elasticClient);

            $filters = $searchDemand->getFilters();

            if (!empty($filters)) {
                foreach ($filters as $key => $value) {
                    if (is_array($value)) {
                        $singleQuery['query']['bool']['filter'][]['terms'][$key] = $value;
                    } else {
                        $singleQuery['query']['bool']['filter'][]['term'][$key] = $value;
                    }
                }
            }

            $searchObj
                ->setQuery($singleQuery);

            $multiSearch->addSearch($searchObj);
            $suggestions[$scope] = [];
        }

        if (count($suggestions) === count($limitingScopes)) {
            unset($suggestions['manual_version']);
        }

        if ($suggestions === []) {
            return [
                'time' => 0,
                'suggestions' => []
            ];
        }

        $results = $multiSearch->search();
        $totalTime = 0;
        $expectedAggregations = array_keys($suggestions);

        foreach ($results as $resultSet) {
            $totalTime += $resultSet->getTotalTime();

            foreach ($resultSet->getAggregations() as $aggsName => $aggregation) {
                if (!in_array($aggsName, $expectedAggregations, true)) {
                    continue;
                }

                $suggestionsForCurrentQuery = [];

                foreach ($aggregation['buckets'] as $bucket) {
                    // Add URL on manual_package
                    if (isset($bucket['manual_slug_hits']['hits']['hits'][0])) {
                        if ($searchDemand->getFilters()['major_versions'] ?? null) {
                            $slug = $bucket['manual_slug_hits']['hits']['hits'][0]['_source']['manual_slug'];
                        } else {
                            $version = end($bucket['manual_slug_hits']['hits']['hits'][0]['_source']['manual_version']);
                            $slug = str_replace($version, 'main', $bucket['manual_slug_hits']['hits']['hits'][0]['_source']['manual_slug']);
                        }

                        $suggestionsForCurrentQuery[] = [
                            'slug' => $slug,
                            'title' => $bucket['key'],
                        ];
                    } else {
                        $suggestionsForCurrentQuery[] = ['title' => $bucket['key']];
                    }
                }

                if ($suggestionsForCurrentQuery === []) {
                    unset($suggestions[$aggsName]);
                    continue;
                }

                $suggestions[$aggsName] = $suggestionsForCurrentQuery;

                if ($searchDemand->areSuggestionsHighlighted()) {
                    $suggestions[$aggsName] = array_map(static function ($value) use ($searchTerms) {
                        return str_ireplace($searchTerms, '<em>' . $searchTerms . '</em>', $value);
                    }, $suggestions[$aggsName]);
                }
            }
        }

        return [
            'time' => $totalTime,
            'suggestions' => $suggestions
        ];
    }

    /**
     * @return array
     * @throws InvalidException
     */
    public function findByQuery(SearchDemand $searchDemand): array
    {
        $query = $this->getDefaultSearchQuery($searchDemand);

        $currentPage = $searchDemand->getPage();

        $search = $this->elasticIndex->createSearch($query);
        $search->getQuery()->setSize($this->perPage);
        $search->getQuery()->setFrom(($currentPage * $this->perPage) - $this->perPage);

        $this->addAggregations($search->getQuery());

        $elasticaResultSet = $search->search();
        $results = $elasticaResultSet->getResults();

        $maxScore = $elasticaResultSet->getMaxScore();
        $aggs = $elasticaResultSet->getAggregations();
        $aggs = $this->sortAggregations($aggs);

        $this->totalHits = $elasticaResultSet->getTotalHits();

        $out = [
            'pagesToLinkTo' => $this->getPages($currentPage),
            'currentPage' => $currentPage,
            'prev' => $currentPage - 1,
            'next' => $currentPage < ceil($this->totalHits / $this->perPage) ? $currentPage + 1 : 0,
            'totalResults' => $this->totalHits,
            'startingAtItem' => ($currentPage * $this->perPage) - ($this->perPage - 1),
            'endingAtItem' => $currentPage * $this->perPage,
            'results' => $results,
            'maxScore' => $maxScore,
            'aggs' => $aggs,
        ];
        if ($this->totalHits <= (int)$out['endingAtItem']) {
            $out['endingAtItem'] = $this->totalHits;
        }
        return $out;
    }

    /**
     * @return array{time: int, results: array<Result>}
     * @throws InvalidException
     */
    public function searchDocumentsForSuggest(SearchDemand $searchDemand): array
    {
        $query = $this->getDefaultSearchQuery($searchDemand);

        $search = $this->elasticIndex->createSearch($query);
        $search->getQuery()->setSize(5);
        $searchResults = $search->search();

        return [
            'time' => $searchResults->getTotalTime(),
            'results' => $searchResults->getResults()
        ];
    }

    private function sortAggregations($aggregations, $direction = 'asc'): array
    {
        uksort($aggregations, function ($a, $b) {
            if ($a === 'Language') {
                return 1;
            }
            if ($b === 'Language') {
                return -1;
            }
            return strcasecmp($a, $b);
        });

        if ($direction === 'desc') {
            $aggregations = \array_reverse($aggregations);
        }
        return $aggregations;
    }

    private function addAggregations(Query $elasticaQuery): void
    {
        $catAggregation = new Terms('Document Type');
        $catAggregation->setField('manual_type');
        $elasticaQuery->addAggregation($catAggregation);

        $trackerAggregation = new Terms('Document');
        $trackerAggregation->setField('manual_title.raw');
        $catAggregation->addAggregation($trackerAggregation);

        $language = new Terms('Language');
        $language->setField('manual_language');
        $elasticaQuery->addAggregation($language);

        $option = new Terms('optionaggs');
        $option->setField('option');
        $elasticaQuery->addAggregation($option);

        $majorVersionsAgg = new Terms('Version');
        $majorVersionsAgg->setField('major_versions');
        $majorVersionsAgg->setSize(10);
        $elasticaQuery->addAggregation($majorVersionsAgg);
    }

    /**
     * @return array
     */
    protected function getPages($currentPage): array
    {
        $numPages = ceil($this->totalHits / $this->perPage);
        $i = 0;
        $maxPages = $numPages;
        if ($numPages > 15 && $currentPage <= 7) {
            $numPages = 15;
        }
        if ($currentPage > 7) {
            $i = $currentPage - 7;
            $numPages = $currentPage + 6;
        }
        if ($numPages > $maxPages) {
            $numPages = $maxPages;
            $i = $maxPages - 15;
        }

        if ($i < 0) {
            $i = 0;
        }

        $out = [];
        while ($i < $numPages) {
            $out[(int)$i] = ($i + 1);
            ++$i;
        }

        return $out;
    }

    private function getDefaultSearchQuery(SearchDemand $searchDemand): array
    {
        $searchTerms = Util::escapeTerm($searchDemand->getQuery());

        // 2 LTS + Main
        $boostedVersions = Typo3VersionMapping::getLtsVersions();
        $boostedVersions[] = Typo3VersionMapping::Dev;

        $query = [
            'query' => [
                'function_score' => [
                    'query' => [
                        'bool' => [
                            'must' => [
                                $searchTerms ? [
                                    'query_string' => [
                                        'query' => $searchTerms,
                                        'fields' => [
                                            'page_title^10',
                                            'snippet_title^20',
                                            'snippet_content',
                                            'manual_title'
                                        ]
                                    ],
                                ] : ['match_all' => new \stdClass()],
                            ],
                        ],
                    ],
                    'functions' => [
                        [
                            'script_score' => [
                                'script' => [
                                    'source' => "int matchCount = 0; for (String term : params.terms) { if (doc['manual_keywords'].contains(term)) { matchCount++; } } return 10 * matchCount;",
                                    'params' => [
                                        'terms' => explode(' ', u($searchTerms)->trim()->toString())
                                    ]
                                ]
                            ]
                        ],
                        [
                            'filter' => [
                                'term' => [
                                    'is_core' => true
                                ],
                            ],
                            'weight' => 5
                        ],
                        [
                            'filter' => [
                                // query matching recent version
                                'terms' => [
                                    'manual_version' => array_map(function (Typo3VersionMapping $version) {
                                        return $version->getVersion();
                                    }, $boostedVersions)
                                ]
                            ],
                            'weight' => 5
                        ],
                    ],
                    'score_mode' => 'sum',
                    'boost_mode' => 'multiply'
                ],
            ],
            'highlight' => [
                'fields' => [
                    'snippet_content' => [
                        'fragment_size' => 400,
                        'number_of_fragments' => 1
                    ]
                ],
                'encoder' => 'html'
            ]
        ];

        $filters = $searchDemand->getFilters();

        if (!empty($filters)) {
            foreach ($filters as $key => $value) {
                if (!is_array($value)) {
                    $value = [$value];
                }

                if ($key === 'major_versions') {
                    $boolVersion = [
                        'bool' => [
                            'should' => [
                                // Either the doc had ONLY version which is "main" (and no other),
                                [
                                    'bool' => [
                                        'filter' => [
                                            ['script' => [
                                                'script' => "doc['$key'].length == 1"
                                            ]],
                                            ['terms' => [$key => ['main']]]
                                        ]
                                    ]
                                ],
                                // Or it has the version requested.
                                ['terms' => [$key => $value]]
                            ]
                        ]
                    ];
                    $query['post_filter']['bool']['must'][] = $boolVersion;
                } else {
                    $query['post_filter']['bool']['must'][] = ['terms' => [$key => $value]];
                }
            }
        }

        // There was no versioning filter - so we force only LTS for core packages.
        if (!isset($filters['major_versions'])) {
            $query['post_filter']['bool']['must'][] = [
                'bool' => [
                    'should' => [
                        // it's core but with a different versioning - always allow
                        ['term' => ['manual_vendor' => ['value' => 'typo3fluid']]],

                        // it's core, allow only LTS
                        ['bool' => [
                            'filter' => [
                                ['term' => ['is_core' => ['value' => true]]],
                                [
                                    'terms' => [
                                        'manual_version' => array_map(function (Typo3VersionMapping $version) {
                                            return $version->getVersion();
                                        }, Typo3VersionMapping::getLtsVersions())
                                    ]
                                ]
                            ]
                        ]],
                    ]
                ]
            ];
        }

        return $query;
    }

    private function getElasticSearchConfig(): array
    {
        $config = [];
        $config['host'] = $_ENV['ELASTICA_HOST'] ?? self::ELASTICA_DEFAULT_CONFIGURATION['host'];
        $config['port'] = $_ENV['ELASTICA_PORT'] ?? self::ELASTICA_DEFAULT_CONFIGURATION['port'];
        $config['path'] = $_ENV['ELASTICA_PATH'] ?? self::ELASTICA_DEFAULT_CONFIGURATION['path'];
        $config['transport'] = $_ENV['ELASTICA_TRANSPORT'] ?? self::ELASTICA_DEFAULT_CONFIGURATION['transport'];
        $config['index'] = $_ENV['ELASTICA_INDEX'] ?? self::ELASTICA_DEFAULT_CONFIGURATION['index'];
        $config['username'] = $_ENV['ELASTICA_USERNAME'] ?? self::ELASTICA_DEFAULT_CONFIGURATION['username'];
        $config['password'] = $_ENV['ELASTICA_PASSWORD'] ?? self::ELASTICA_DEFAULT_CONFIGURATION['password'];

        return $config;
    }
}
