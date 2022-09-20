<?php

/**
 * SmartObjectInformationService.php.
 */
declare(strict_types=1);

namespace HDNET\Autoloader\Service;

use Doctrine\Common\Annotations\AnnotationReader;
use HDNET\Autoloader\Annotation\DatabaseKey;
use HDNET\Autoloader\DataSet;
use HDNET\Autoloader\Mapper;
use HDNET\Autoloader\Utility\ArrayUtility;
use HDNET\Autoloader\Utility\ClassNamingUtility;
use HDNET\Autoloader\Utility\ExtendedUtility;
use HDNET\Autoloader\Utility\IconUtility;
use HDNET\Autoloader\Utility\ModelUtility;
use HDNET\Autoloader\Utility\TranslateUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * SmartObjectInformationService.
 */
class SmartObjectInformationService
{
    /**
     * Get a instance of this object.
     */
    public static function getInstance(): self
    {
        return GeneralUtility::makeInstance(self::class);
    }

    /**
     * Get database information.
     *
     * @param $modelClassName
     */
    public function getDatabaseInformation($modelClassName): string
    {
        $tableName = ModelUtility::getTableName($modelClassName);
        $custom = $this->getCustomDatabaseInformation($modelClassName);

        // disable complete table generation
        // for extending existing tables
        if ('' !== ModelUtility::getTableNameByModelReflectionAnnotation($modelClassName)) {
            return $this->generateSqlQuery($tableName, $custom);
        }

        return $this->generateCompleteSqlQuery($modelClassName, $tableName, $custom);
    }

    /**
     * Get the custom Model field TCA structure.
     *
     * @param $modelClassName
     *
     * @return array<int|string, mixed[]>
     */
    public function getCustomModelFieldTca(string $modelClassName, array &$searchFields = []): array
    {
        $modelInformation = ClassNamingUtility::explodeObjectModelName($modelClassName);
        $extensionName = GeneralUtility::camelCaseToLowerCaseUnderscored($modelInformation['extensionName']);
        $tableName = ModelUtility::getTableName($modelClassName);
        $customFieldInfo = ModelUtility::getCustomModelFields($modelClassName);
        $searchFields = [];
        $customFields = [];
        foreach ($customFieldInfo as $info) {
            $key = $tableName . '.' . $info['name'];

            if ($this->useTableNameFileBase()) {
                // Without prefix !
                $key = $info['name'];
            }

            try {
                TranslateUtility::assureLabel($key, $extensionName, $info['name'], null, $tableName);
                $label = TranslateUtility::getLllOrHelpMessage($key, $extensionName, $tableName);
            } catch (\Exception $ex) {
                $label = $info['name'];
            }

            /** @var Mapper $mapper */
            $mapper = ExtendedUtility::create(Mapper::class);
            $field = $mapper->getTcaConfiguration(trim($info['var'], '\\'), $info['name'], $label);

            // RTE
            if ($info['rte']) {
                $field['config']['type'] = 'text';
                $field['config']['enableRichtext'] = '1';
                $field['config']['richtextConfiguration'] = 'default';
                $field['config']['softref'] = 'typolink_tag,email[subst],url';
                $field['defaultExtras'] = 'richtext:rte_transform[flag=rte_enabled|mode=ts_css]';
            }

            $searchFields[] = $info['name'];
            $customFields[$info['name']] = $field;
        }

        return $customFields;
    }

    /**
     * Pre build TCA information for the given model.
     *
     * @return mixed[]
     */
    public function getTcaInformation(string $modelClassName): array
    {
        $modelInformation = ClassNamingUtility::explodeObjectModelName($modelClassName);
        $extensionName = GeneralUtility::camelCaseToLowerCaseUnderscored($modelInformation['extensionName']);
        $reflectionTableName = ModelUtility::getTableNameByModelReflectionAnnotation($modelClassName);
        $tableName = ModelUtility::getTableNameByModelName($modelClassName);

        $searchFields = [];
        $customFields = $this->getCustomModelFieldTca($modelClassName, $searchFields);

        if ('' !== $reflectionTableName) {
            $customConfiguration = [
                'columns' => $customFields,
            ];
            $base = \is_array($GLOBALS['TCA'][$reflectionTableName]) ? $GLOBALS['TCA'][$reflectionTableName] : [];

            return ArrayUtility::mergeRecursiveDistinct($base, $customConfiguration);
        }

        $excludes = ModelUtility::getSmartExcludesByModelName($modelClassName);

        $dataSet = $this->getDataSet();
        $dataImplementations = $dataSet->getAllAndExcludeList($excludes);
        $baseTca = $dataSet->getTcaInformation($dataImplementations, $tableName);

        // title
        $fields = array_keys($customFields);
        $labelField = 'title';
        if (!\in_array($labelField, $fields, true)) {
            $labelField = $fields[0];
        }

        try {
            TranslateUtility::assureLabel($tableName, $extensionName, null, null, $tableName);
        } catch (\Exception $ex) {
            // Do not handle the error of the assureLabel method
        }
        if (!isset(($baseTca['columns'])) || !\is_array($baseTca['columns'])) {
            $baseTca['columns'] = [];
        }
        $baseTca['columns'] = ArrayUtility::mergeRecursiveDistinct($baseTca['columns'], $customFields);

        // items
        $showitem = $fields;
        if (!\in_array('language', $excludes, true)) {
            $showitem[] = '--div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:language,--palette--;;language';
        }

        if (!\in_array('workspaces', $excludes, true)) {
            $baseTca['ctrl']['shadowColumnsForNewPlaceholders'] = $baseTca['ctrl']['shadowColumnsForNewPlaceholders'] ?? '';
            $baseTca['ctrl']['shadowColumnsForNewPlaceholders'] .= ',' . $labelField;
        }

        $languagePrefix = 'LLL:EXT:frontend/Resources/Private/Language/';
        $languagePrefixCore = 'LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf';
        if (!\in_array('enableFields', $excludes, true)) {
            $showitem[] = '--div--;' . $languagePrefixCore . ':access';
            $showitem[] = '--palette--;' . $languagePrefix . 'locallang_tca.xlf:pages.palettes.access;access';
        }
        $showitem[] = '--div--;' . $languagePrefix . 'locallang_ttc.xlf:tabs.extended';

        $overrideTca = [
            'ctrl' => [
                'title' => $this->getTcaTitle($tableName, $extensionName),
                'label' => $labelField,
                'tstamp' => 'tstamp',
                'crdate' => 'crdate',
                'cruser_id' => 'cruser_id',
                'dividers2tabs' => true,
                'sortby' => 'sorting',
                'delete' => 'deleted',
                'searchFields' => implode(',', $searchFields),
                'iconfile' => IconUtility::getByModelName($modelClassName, true),
            ],
            'types' => [
                '1' => ['showitem' => implode(',', $showitem)],
            ],
            'palettes' => [
                'access' => ['showitem' => 'starttime, endtime, --linebreak--, hidden, editlock, --linebreak--, fe_group'],
            ],
        ];

        return ArrayUtility::mergeRecursiveDistinct($baseTca, $overrideTca);
    }

    /**
     * Check if table name file base is used.
     */
    protected function useTableNameFileBase(): bool
    {
        $configuration = (array)GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('autoloader');

        return isset($configuration['enableLanguageFileOnTableBase']) ? (bool)$configuration['enableLanguageFileOnTableBase'] : false;
    }

    /**
     * Get custom database information for the given model.
     *
     * @return string[]
     */
    protected function getCustomDatabaseInformation(string $modelClassName): array
    {
        $fieldInformation = ModelUtility::getCustomModelFields($modelClassName);
        $fields = [];
        foreach ($fieldInformation as $info) {
            if ('' === $info['db']) {
                try {
                    $info['db'] = $this->getDatabaseMappingByVarType($info['var']);
                } catch (\Exception $exception) {
                    throw new \Exception('Error for mapping in ' . $modelClassName . ' in property ' . $info['property'] . ' with problem: ' . $exception->getMessage(), 123681, $exception);
                }
            } else {
                try {
                    $info['db'] = $this->getDatabaseMappingByVarType($info['db']);
                } catch (\Exception $ex) {
                    // Do not handle the getDatabaseMappingByVarType by db, Fallback is the var call
                }
            }
            $fields[] = '`' . $info['name'] . '` ' . $info['db'];
        }

        return $fields;
    }

    /**
     * Get the right mapping.
     *
     * @param $var
     *
     * @throws \HDNET\Autoloader\Exception
     */
    protected function getDatabaseMappingByVarType(string $var): string
    {
        /** @var Mapper $mapper */
        $mapper = ExtendedUtility::create(Mapper::class);

        return $mapper->getDatabaseDefinition($var);
    }

    /**
     * Generate SQL Query.
     */
    protected function generateSqlQuery(string $tableName, array $fields): string
    {
        if (empty($fields)) {
            return '';
        }

        return LF . 'CREATE TABLE ' . $tableName . ' (' . LF . implode(',' . LF, $fields) . LF . ');' . LF;
    }

    /**
     * Generate complete SQL Query.
     */
    protected function generateCompleteSqlQuery(string $modelClassName, string $tableName, array $custom): string
    {
        $fields = [];
        $fields = $custom;

        $excludes = ModelUtility::getSmartExcludesByModelName($modelClassName);
        $dataSet = $this->getDataSet();
        $dataImplementations = $dataSet->getAllAndExcludeList($excludes);

        // add data set fields
        $fields = array_merge($fields, $dataSet->getDatabaseSqlInformation($dataImplementations, $tableName));

        // default keys
        //$fields[] = 'PRIMARY KEY (uid)';
        //$fields[] = 'KEY parent (pid)';

        // add custom keys set by @key annotations

        $annotationReader = GeneralUtility::makeInstance(AnnotationReader::class);
        $key = $annotationReader->getClassAnnotation(new \ReflectionClass($modelClassName), DatabaseKey::class);
        if (null !== $key) {
            $additionalKeys = [(string)$key];
            array_walk($additionalKeys, function (&$item): void {
                $item = 'KEY ' . $item;
            });
            $fields = array_merge($fields, $additionalKeys);
        }

        // add data set keys
        $fields = array_merge($fields, $dataSet->getDatabaseSqlKeyInformation($dataImplementations, $tableName));

        return $this->generateSqlQuery($tableName, $fields);
    }

    /**
     * Get the data set object.
     */
    protected function getDataSet(): DataSet
    {
        return GeneralUtility::makeInstance(DataSet::class);
    }

    /**
     * Get TCA title.
     */
    protected function getTcaTitle(string $tableName, string $extensionName): string
    {
        return TranslateUtility::getLllOrHelpMessage($tableName, $extensionName, $tableName);
    }
}
