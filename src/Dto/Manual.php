<?php

namespace App\Dto;

use App\Config\ManualType;
use Symfony\Component\Finder\Finder;

class Manual
{
    public function __construct(
        private readonly string $absolutePath,
        private readonly string $name,
        private readonly string $type,
        private readonly string $version,
        private readonly string $language,
        private readonly string $slug,
        private readonly array $keywords,
        private readonly string $vendor = '',
        private readonly bool $isCore = false,
    ) {
    }

    public static function createFromFolder(\SplFileInfo $folder, $changelog = false): Manual
    {
        $pathArray = explode('/', $folder->getPathname());
        if ($changelog) {
            // e.g. c/typo3/cms-core/master/en-us/Changelog/9.4
            $values = array_slice($pathArray, -7, 7);
            [$_, $vendor, $name, $__, $language, $type, $version] = $values;
            $type = \strtolower($type);
            $name .= '-' . $type;
        } elseif (count($pathArray) >= 4 && array_slice($pathArray, -4, 1)[0] === ManualType::ExceptionReference->getKey()) {
            // typo3 exceptions manuals have different structure than other manuals
            // they are located in /typo3cms/exceptions/ folder, and we also ignore one other
            // folder inside /typo3cms/ through services.yaml file and the docsearch.excluded_directories parameter
            $values = array_slice($pathArray, -5, 5);
            array_shift($values); // remove the Web part from the path
            [$type, $name, $version, $language] = $values;
            $vendor = 'typo3';
        } else {
            // e.g. "c/typo3/cms-workspaces/9.5/en-us"
            $values = array_slice($pathArray, -5, 5);
            [$type, $vendor, $name, $version, $language] = $values;
        }

        $map = ManualType::getMap();
        $type = $map[$type] ?? $type;
        $isCore = in_array($type, [ManualType::SystemExtension->value, ManualType::Typo3Manual->value, ManualType::CoreChangelog->value], true);

        $keywords = [];
        if ($type === ManualType::SystemExtension->value || $type === ManualType::CommunityExtension->value) {
            $keywords[] = $name;
        }

        return new Manual(
            $folder,
            $name,
            $type,
            $version,
            $language,
            implode('/', $values),
            $keywords,
            $vendor,
            $isCore,
        );
    }

    public function getFilesWithSections(): Finder
    {
        $finder = new Finder();
        $finder
            ->files()
            ->in($this->getAbsolutePath())
            ->name('*.html')
            ->notName(['search.html', 'genindex.html', 'Targets.html', 'Quicklinks.html'])
            ->notPath(['_buildinfo', '_images', '_panels_static', '_sources', '_static', 'singlehtml']);

        if ($this->getTitle() === 'typo3/cms-core') {
            $finder->notPath('Changelog');
        }
        return $finder;
    }

    /**
     * TYPO3 Core Changelogs are treated as submanuals from typo3/cms-core manual
     *
     * Changelogs from other packages are not treated as submanuals, because they
     * can have different structure and will be threat as normal manual pages.
     *
     * @return array<Manual>
     */
    public function getSubManuals(): array
    {
        if ($this->getTitle() !== 'typo3/cms-core') {
            return [];
        }
        if ($this->getVersion() !== 'main') {
            return [];
        }
        $finder = new Finder();
        $finder
            ->directories()
            ->in($this->getAbsolutePath() . '/Changelog')
            ->depth(0);

        $subManuals = [];

        foreach ($finder as $changelogFolder) {
            $subManuals[] = self::createFromFolder($changelogFolder, true);
        }
        return $subManuals;
    }

    public function getAbsolutePath(): string
    {
        return $this->absolutePath;
    }

    public function getTitle(): string
    {
        $titleParts = [];

        if ($this->vendor !== '') {
            $titleParts[] = $this->vendor;
        }

        if ($this->name !== '') {
            $titleParts[] = $this->name;
        }

        return implode('/', $titleParts);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVendor(): string
    {
        return $this->vendor;
    }

    public function isCore(): bool
    {
        return $this->isCore ?? false;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getKeywords(): array
    {
        return $this->keywords;
    }
}
