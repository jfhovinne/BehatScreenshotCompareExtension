<?php

namespace Cevou\Behat\ScreenshotCompareExtension\Context;

use Behat\MinkExtension\Context\RawMinkContext;
use Gaufrette\Filesystem as GaufretteFilesystem;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

class RawScreenshotCompareContext extends RawMinkContext implements ScreenshotCompareAwareContext {
  private $screenshotCompareConfigurations;
  private $screenshotCompareParameters;

  /**
   * {@inheritdoc}
   */
  public function setScreenshotCompareConfigurations(array $configurations) {
    $this->screenshotCompareConfigurations = $configurations;
  }

  /**
   * {@inheritdoc}
   */
  public function setScreenshotCompareParameters(array $parameters) {
    $this->screenshotCompareParameters = $parameters;
  }

  public function getScreenshotParameters() {
    return $this->screenshotCompareParameters;
  }

  public function getScreenshotConfiguration() {
    return $this->screenshotCompareConfigurations;
  }

  /**
   * @param $sessionName
   * @param $fileName
   * @param $selector
   *
   * @throws \LogicException
   * @throws \ImagickException
   * @throws \Symfony\Component\Filesystem\Exception\FileNotFoundException
   */
  public function compareScreenshot($sessionName, $fileName, $selector = NULL) {
    // Get the current session and config.
    $this->assertSession($sessionName);
    $session = $this->getSession($sessionName);

    if (!array_key_exists($sessionName, $this->getScreenshotConfiguration())) {
      throw new \LogicException(sprintf('The configuration for session \'%s\' is not defined.', $sessionName));
    }
    $screenshotParameters = $this->getScreenshotParameters();

    $configuration = $this->getScreenshotConfiguration()[$sessionName];

    $sourceFilesystem = new SymfonyFilesystem();

    // Iterate over the breakpoints and test the screenshots.
    foreach ($screenshotParameters['screenshot_config']['breakpoints'] as $breakpoint_name => $parameters) {
      $full_filename = $screenshotParameters['screenshot_dir'] .
        DIRECTORY_SEPARATOR . $breakpoint_name .
        DIRECTORY_SEPARATOR . $fileName;

      if (!$sourceFilesystem->exists($full_filename)) {
        throw new FileNotFoundException(NULL, 0, NULL, $full_filename);
      }

      $testScreenshot = new \Imagick();
      $session->resizeWindow($parameters['width'], $parameters['height']);
      $testScreenshot->readImageBlob($session->getScreenshot());
      $testGeometry = $testScreenshot->getImageGeometry();

      // Crop the image according to the settings.
      if (array_key_exists('crop', $configuration) || $selector) {
        // If we crop it only to a given HTML element on page.
        if ($selector) {
          $element_position = $this->getSession()
            ->evaluateScript('document.body.querySelector("' . $selector . '").getBoundingClientRect();');
          $crop['top'] = floor($element_position['top']);
          $crop['bottom'] = ceil($element_position['bottom']);
          $crop['left'] = floor($element_position['left']);
          $crop['right'] = ceil($element_position['right']);
          $crop['width'] = $crop['right'] - $crop['left'];
          $crop['height'] = $crop['bottom'] - $crop['top'];
        }
        else {
          $crop = $configuration['crop'];
          $crop['width'] = $testGeometry['width'] - $crop['right'] - $crop['left'];
          $crop['height'] = $testGeometry['height'] - $crop['top'] - $crop['bottom'];
        }

        $testScreenshot->cropImage($crop['width'], $crop['height'], $crop['left'], $crop['top']);

        // Refresh geometry information after cropping.
        $testGeometry = $testScreenshot->getImageGeometry();
      }

      // Load reference image.
      $referenceScreenshot = new \Imagick($full_filename);
      $referenceGeometry = $referenceScreenshot->getImageGeometry();

      // ImageMagick can only compare files which have the same size.
      if ($testGeometry !== $referenceGeometry) {
        $this->generateReport('compare', $configuration, [
          'filename' => $fileName,
          'screenshots' => [
            'test' => $testScreenshot,
            'reference' => $referenceScreenshot,
          ],
        ]);

        throw new \ImagickException(sprintf(
          "Screenshots of '%s' on the '%s' breakpoint don't have an equal geometry.\nShould be %sx%s but is %sx%s",
          $fileName,
          $breakpoint_name,
          $referenceGeometry['width'],
          $referenceGeometry['height'],
          $testGeometry['width'],
          $testGeometry['height']
        ));
      }
      // Make comparison.
      $result = $referenceScreenshot->compareImages($testScreenshot, \Imagick::METRIC_ROOTMEANSQUAREDERROR);

      if ($result[1] > 0) {
        /** @var \Imagick $diffScreenshot */
        $diffScreenshot = $result[0];
        $diffScreenshot->setImageFormat("png");

        $this->generateReport('compare', $configuration, [
          'filename' => $fileName,
          'screenshots' => [
            'diff' => $diffScreenshot,
            'test' => $testScreenshot,
            'reference' => $referenceScreenshot,
          ],
        ]);

        throw new \ImagickException(sprintf("Files are not equal for file '%s' breakpoint '%s'.", $fileName, $breakpoint_name));
      }
    }
  }

  /**
   * @param $type
   * @param $configuration
   * @param $report
   */
  private function generateReport($type, $configuration, $report) {
    /** @var GaufretteFilesystem $targetFilesystem */
    $targetFilesystem = $configuration['adapter'];
    $timestamp = date('ymdHis');
    $reportFileName = sprintf(
      '%s_%s.%s',
      $this->getMinkParameter('browser_name'),
      $timestamp,
      'png'
    );
    $fileName = $report['filename'];
    $images = '';
    $buttons = '';
    $i = 0;
    foreach ($report['screenshots'] as $key => $data) {
      $currentFileName = $key . '_' . $reportFileName;
      $targetFilesystem->write($currentFileName, $data);
      if ($i > 0) {
        $buttons .= '<button onmousedown="show(event)" onmouseup="hide(event)">' . $key . '</button>';
      }
      $images .= '<img id="' . $key . '" style="position: absolute; top: 40px;' . ($i > 0 ? ' display:none;' : '') . '" src="' . $currentFileName . '" />';
      $i++;
    }

    $html = <<<EOT
        <html>
          <head>
            <title>$fileName</title>
            <script>
              function show(e) {
                  var id = e.target.innerHTML;
                  document.getElementById(id).style.display = "inherit";
              }

              function hide(e) {
                  var id = e.target.innerHTML;
                  document.getElementById(id).style.display = "none";
              }
            </script>
          </head>
          <body style="padding: 0px; margin: 0px;">
            <p style="margin: 0px; padding: 10px; position: fixed; top: 0px; left: 0px; z-index: 1000; background-color: #ccc;">SHOW: $buttons</p>
            $images
          </body>
         </html>
EOT;

    $targetFilesystem->write($fileName . '.' . $timestamp . '.html', $html);
  }


}
