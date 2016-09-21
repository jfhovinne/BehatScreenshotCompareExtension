<?php

namespace Cevou\Behat\ScreenshotCompareExtension\Context;

use Behat\Mink\Exception\ExpectationException;

class ScreenshotCompareContext extends RawScreenshotCompareContext {

  /**
   * Checks if the screenshot of the default session  is equal to a defined screen
   *
   * @Then /^the screenshot should be equal to "(?P<fileName>[^"]+)"$/
   */
  public function assertScreenshotCompare($fileName) {
    $this->compareScreenshot($this->getMink()
      ->getDefaultSessionName(), $fileName);
  }

  /**
   * Checks if the screenshot of the default session is equal to a defined screen.
   *
   * @Then /^the screenshot from "(?P<selector>[^"]+)" element should be equal to "(?P<fileName>[^"]+)"$/
   */
  public function assertElementScreenshotCompare($selector, $fileName) {
    $page = $this->getSession()->getPage();
    $element = $page->find('css', $selector);
    if (NULL === $element) {
      throw new \Exception('The element is not found');
    }

    $this->compareScreenshot($this->getMink()
      ->getDefaultSessionName(), $fileName, $selector);
  }

  /**
   * Helper to generate the screenshot.
   *
   * @Then I generate the screenshot :filename
   */
  public function iGenerateTheScreenshot($filename, $selector = NULL) {
    $screenshot_parameters = $this->getScreenshotParameters();
    $sessionName = $this->getMink()->getDefaultSessionName();

    if (!array_key_exists($sessionName, $this->getScreenshotConfiguration())) {
      throw new \LogicException(sprintf('The configuration for session \'%s\' is not defined.', $sessionName));
    }
    $screenshot_configuration = $this->getScreenshotConfiguration()[$sessionName];

    foreach ($screenshot_parameters['screenshot_config']['breakpoints'] as $breakpoint_name => $parameters) {
      $this->getSession()
        ->resizeWindow($parameters['width'], $parameters['height']);
      $screenshot = $this->getSession()->getScreenshot();

      //Crop the image according to the settings.
      if (array_key_exists('crop', $screenshot_configuration) || $selector) {
        // Initiate Imagick object.
        $actualScreenshot = new \Imagick();
        $actualScreenshot->readImageBlob($screenshot);

        // Get the current size.
        $actualGeometry = $actualScreenshot->getImageGeometry();

        // If we crop it only to a given HTML element on page.
        if ($selector) {
          $element_position = $this->getSession()->evaluateScript('document.body.querySelector("' . $selector . '").getBoundingClientRect();');
          $crop['top'] = floor($element_position['top']);
          $crop['bottom'] = ceil($element_position['bottom']);
          $crop['left'] = floor($element_position['left']);
          $crop['right'] = ceil($element_position['right']);
          $crop['width'] = $crop['right'] - $crop['left'];
          $crop['height'] = $crop['bottom'] - $crop['top'];
        }
        else {
          $crop = $screenshot_configuration['crop'];
          $crop['width'] = $actualGeometry['width'] - $crop['right'] - $crop['left'];
          $crop['height'] = $actualGeometry['height'] - $crop['top'] - $crop['bottom'];
        }

        // Crop the image.
        $actualScreenshot->cropImage($crop['width'], $crop['height'], $crop['left'], $crop['top']);
        $actualScreenshot->setImageFormat("png");
        $screenshot = $actualScreenshot->getImageBlob();
      }

      // Create the directories if they do not yet exist.
      $directory = $screenshot_parameters['screenshot_dir'] . '/' . $breakpoint_name . '/';
      $full_name = $directory . $filename;
      if (!file_exists($full_name)) {
        if (!file_exists($directory)) {
          mkdir($directory, 0777, TRUE);
        }
        file_put_contents($full_name, $screenshot);
      }
      else {
        throw new ExpectationException(
          'Tried to generate ' . $full_name . ' but it already exists.',
          $this->getSession()
        );
      }
    }
  }

  /**
   * Helper to generate the screenshot.
   *
   * @Then I generate the screenshot :filename from :selector element
   */
  public function iGenerateTheScreenshotFromElement($filename, $selector) {
    $page = $this->getSession()->getPage();
    $element = $page->find('css', $selector);
    if (NULL === $element) {
      throw new \Exception('The element is not found');
    }
    $this->iGenerateTheScreenshot($filename, $selector);
  }

}
