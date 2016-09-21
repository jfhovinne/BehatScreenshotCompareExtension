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

      $actualScreenshot = new \Imagick();
      $session->resizeWindow($parameters['width'], $parameters['height']);
      $actualScreenshot->readImageBlob($session->getScreenshot());
      $actualGeometry = $actualScreenshot->getImageGeometry();

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
          $crop['width'] = $actualGeometry['width'] - $crop['right'] - $crop['left'];
          $crop['height'] = $actualGeometry['height'] - $crop['top'] - $crop['bottom'];
        }

        $actualScreenshot->cropImage($crop['width'], $crop['height'], $crop['left'], $crop['top']);

        // Refresh geomerty information.
        $actualGeometry = $actualScreenshot->getImageGeometry();
      }

      $compareScreenshot = new \Imagick($full_filename);
      $compareGeometry = $compareScreenshot->getImageGeometry();

      // ImageMagick can only compare files which have the same size.
      if ($actualGeometry !== $compareGeometry) {
        throw new \ImagickException(sprintf(
          "Screenshots don't have an equal geometry. Should be %sx%s but is %sx%s",
          $compareGeometry['width'],
          $compareGeometry['height'],
          $actualGeometry['width'],
          $actualGeometry['height']
        ));
      }

      $result = $actualScreenshot->compareImages($compareScreenshot, \Imagick::METRIC_ROOTMEANSQUAREDERROR);

      if ($result[1] > 0) {
        /** @var GaufretteFilesystem $targetFilesystem */
        $targetFilesystem = $configuration['adapter'];
        $datestamp = date('ymdHis');
        $diffFileName = sprintf(
          '%s_%s.%s',
          $this->getMinkParameter('browser_name'),
          $datestamp,
          'png'
        );

        /** @var \Imagick $diffScreenshot */
        $diffScreenshot = $result[0];
        $diffScreenshot->setImageFormat("png");
        $targetFilesystem->write('diff_' . $diffFileName, $diffScreenshot);
        $targetFilesystem->write('test_' . $diffFileName, $actualScreenshot);
        $targetFilesystem->write('reference_' . $diffFileName, $compareScreenshot);

        $html = <<<EOT
        <html>
          <head>
            <title>$fileName</title>
            <script>
              function show(id) {
                  document.getElementById(id).style.display = "inherit";
              }
              
              function hide(id) {
                  document.getElementById(id).style.display = "none";
              }
            </script>
          </head>
          <body style="padding: 0px; margin: 0px;">
            <p style="margin: 0px; padding: 10px; position: fixed; top: 0px; left: 0px; z-index: 1000; background-color: #ccc;">SHOW: <button onmousedown="show('test')" onmouseup="hide('test')">test image</button> <button onmousedown="show('reference')" onmouseup="hide('reference')">reference image</button></p>
            <img id="diff" style="position: absolute;" src="diff_$diffFileName" />
            <img id="test" style="position: absolute; display: none;" src="test_$diffFileName" />
            <img id="reference" style="position: absolute; display: none;" src="reference_$diffFileName" />
          </body>
         </html>
EOT;

        $targetFilesystem->write($fileName . '.' . $datestamp . '.html', $html);

        throw new \ImagickException(sprintf("Files are not equal for file '%s' breakpoint '%s'.\nDiff saved to %s", $fileName, $breakpoint_name, $diffFileName));
      }
    }
  }

}
