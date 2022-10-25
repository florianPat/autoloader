<?php

/**
 * DataSet information for languages.
 */
declare(strict_types=1);

namespace HDNET\Autoloader\DataSet;

use HDNET\Autoloader\DataSetInterface;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;

/**
 * DataSet information for languages.
 */
class Language implements DataSetInterface
{
    /**
     * Get TCA information.
     *
     * @return array<string, mixed[]>
     */
    public function getTca(string $tableName): array
    {
        if (version_compare(VersionNumberUtility::getCurrentTypo3Version(), '11.2.0', '<=')) {
            $languageConfiguration = [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => '0',
                'special' => 'languages',
                'items' => [
                    [
                        'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.allLanguages',
                        -1,
                        'flags-multiple',
                    ],
                ],
            ];
        } else {
            $languageConfiguration = [
                'type' => 'language',
            ];
        }

        return [
            'ctrl' => [
                'languageField' => 'sys_language_uid',
                'transOrigPointerField' => 'l10n_parent',
                'transOrigDiffSourceField' => 'l10n_diffsource',
            ],
            'columns' => [
                'sys_language_uid' => [
                    'exclude' => 1,
                    'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.language',
                    'config' => $languageConfiguration,
                ],
                'l10n_parent' => [
                    'displayCond' => 'FIELD:sys_language_uid:>:0',
                    'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.l18n_parent',
                    'config' => [
                        'type' => 'select',
                        'renderType' => 'selectSingle',
                        'default' => 0,
                        'items' => [
                            [
                                '',
                                0,
                            ],
                        ],
                        'foreign_table' => $tableName,
                        'foreign_table_where' => 'AND ' . $tableName . '.pid=###CURRENT_PID### AND ' . $tableName . '.sys_language_uid IN (-1,0)',
                    ],
                ],
                'l10n_diffsource' => [
                    'config' => [
                        'type' => 'passthrough',
                    ],
                ],
            ],
            'palettes' => [
                'language' => ['showitem' => 'sys_language_uid, l10n_parent, l10n_diffsource'],
            ],
        ];
    }

    /**
     * Get database sql information.
     *
     * @return mixed[]
     */
    public function getDatabaseSql(string $tableName): array
    {
        return [
            //'sys_language_uid int(11) DEFAULT \'0\' NOT NULL',
            //'l10n_parent int(11) DEFAULT \'0\' NOT NULL',
            //'l10n_diffsource mediumblob',
        ];
    }

    /**
     * Get database sql key information.
     *
     * @return string[]
     */
    public function getDatabaseSqlKey(): array
    {
        return [
            'KEY language (l10n_parent,sys_language_uid)',
        ];
    }
}
