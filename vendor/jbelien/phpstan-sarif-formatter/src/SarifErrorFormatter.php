<?php

declare(strict_types=1);

namespace PHPStanSarifErrorFormatter;

use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use Nette\Utils\Json;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\Output;
use PHPStan\File\RelativePathHelper;
use PHPStan\Internal\ComposerHelper;

class SarifErrorFormatter implements ErrorFormatter
{

  private const URI_BASE_ID = 'WORKINGDIR';

  public function __construct(
    private RelativePathHelper $relativePathHelper,
    private string $currentWorkingDirectory,
    private bool $pretty,
  ) {
  }

  public function formatErrors(AnalysisResult $analysisResult, Output $output): int
  {
    $phpstanVersion = ComposerHelper::getPhpStanVersion();

    $rules = [];

    $tool = [
      'driver' => [
        'name' => 'PHPStan',
        'fullName' => 'PHP Static Analysis Tool',
        'informationUri' => 'https://phpstan.org',
        'version' => $phpstanVersion,
        'semanticVersion' => $phpstanVersion,
        'rules' => $rules,
      ],
    ];

    $originalUriBaseIds = [
      self::URI_BASE_ID => [
        'uri' => 'file://' . $this->currentWorkingDirectory . '/',
      ],
    ];

    $results = [];

    foreach ($analysisResult->getFileSpecificErrors() as $fileSpecificError) {
      $result = [
        'level' => $fileSpecificError->canBeIgnored() ? 'warning' : 'error',
        'message' => [
          'text' => $fileSpecificError->getMessage(),
        ],
        'locations' => [
          [
            'physicalLocation' => [
              'artifactLocation' => [
                'uri' => $this->relativePathHelper->getRelativePath($fileSpecificError->getFile()),
                'uriBaseId' => self::URI_BASE_ID,
              ],
              'region' => [
                'startLine' => $fileSpecificError->getLine(),
              ],
            ],
          ],
        ],
        'properties' => [
          'ignorable' => $fileSpecificError->canBeIgnored(),
        ],
      ];

      if ($fileSpecificError->getIdentifier() !== null) {
        $result['ruleId'] = $fileSpecificError->getIdentifier();

        $ruleID = $fileSpecificError->getIdentifier();
        $rules[$ruleID] = [
          'id' => $ruleID,
          'shortDescription' => [
            'text' => $fileSpecificError->getMessage()
          ],
        ];

        if ($fileSpecificError->getTip() !== null) {
          $rules[$ruleID]['help']['text'] = $fileSpecificError->getTip();
        }
      }

      if ($fileSpecificError->getTip() !== null) {
        $result['properties']['tip'] = $fileSpecificError->getTip();
      }

      $results[] = $result;
    }

    foreach ($analysisResult->getNotFileSpecificErrors() as $notFileSpecificError) {
      $results[] = [
        'level' => 'error',
        'message' => [
          'text' => $notFileSpecificError,
        ],
      ];
    }

    foreach ($analysisResult->getWarnings() as $warning) {
      $results[] = [
        'level' => 'warning',
        'message' => [
          'text' => $warning,
        ],
      ];
    }

    $tool['driver']['rules'] = array_values($rules);

    $sarif = [
      '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
      'version' => '2.1.0',
      'runs' => [
        [
          'tool' => $tool,
          'originalUriBaseIds' => $originalUriBaseIds,
          'results' => $results,
        ],
      ],
    ];

    $json = Json::encode($sarif, $this->pretty ? Json::PRETTY : 0);

    $output->writeRaw($json);

    return $analysisResult->hasErrors() ? 1 : 0;
  }
}