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
   * @param $sessionName
   * @param $fileName
   * @param $selector
   *
   * @throws \LogicException
   * @throws \ImagickException
   * @throws ExpectationException
   */
  public function generateScreenshot($sessionName, $fileName, $selector = NULL) {
    // Get the current session and config.
    $this->assertSession($sessionName);
    $session = $this->getSession($sessionName);
    $screenshot_parameters = $this->getScreenshotParameters();
    $screenshot_configuration = $this->getScreenshotConfiguration()[$sessionName];

    if (!array_key_exists($sessionName, $this->getScreenshotConfiguration())) {
      throw new \LogicException(sprintf('The configuration for session \'%s\' is not defined.', $sessionName));
    }

    foreach ($screenshot_parameters['screenshot_config']['breakpoints'] as $breakpoint_name => $parameters) {
      $session->resizeWindow($parameters['width'], $parameters['height']);
      $screenshot = $session->getScreenshot();

      // Crop the image according to the settings.
      if (array_key_exists('crop', $screenshot_configuration) || $selector) {
        // Initiate Imagick object.
        $referenceScreenshot = new \Imagick();
        $referenceScreenshot->readImageBlob($screenshot);

        // Get the current size.
        $geometry = $referenceScreenshot->getImageGeometry();

        // If we crop it only to a given HTML element on page.
        if ($selector) {
          $element_position = $session->evaluateScript('document.body.querySelector("' . $selector . '").getBoundingClientRect();');
          $crop['top'] = floor($element_position['top']);
          $crop['bottom'] = ceil($element_position['bottom']);
          $crop['left'] = floor($element_position['left']);
          $crop['right'] = ceil($element_position['right']);
          $crop['width'] = $crop['right'] - $crop['left'];
          $crop['height'] = $crop['bottom'] - $crop['top'];
        }
        else {
          $crop = $screenshot_configuration['crop'];
          $crop['width'] = $geometry['width'] - $crop['right'] - $crop['left'];
          $crop['height'] = $geometry['height'] - $crop['top'] - $crop['bottom'];
        }

        // Crop the image.
        $referenceScreenshot->cropImage($crop['width'], $crop['height'], $crop['left'], $crop['top']);
        $referenceScreenshot->setImageFormat("png");
        $screenshot = $referenceScreenshot->getImageBlob();
      }

      // Create the directories if they do not yet exist.
      $directory = $screenshot_parameters['screenshot_dir'] . '/' . $breakpoint_name . '/';
      $full_name = $directory . $fileName;
      if (!file_exists($full_name)) {
        if (!file_exists($directory)) {
          mkdir($directory, 0777, TRUE);
        }
        file_put_contents($full_name, $screenshot);
      }
      else {
        throw new ExpectationException(
          'Tried to generate ' . $full_name . ' but it already exists.',
          $session
        );
      }
    }
  }

  /**
   * Helper to generate the screenshot.
   *
   * @Then I generate the screenshot :filename
   */
  public function iGenerateTheScreenshot($filename) {
    $this->generateScreenshot($this->getMink()
      ->getDefaultSessionName(), $filename);
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
    $this->generateScreenshot($this->getMink()
      ->getDefaultSessionName(), $filename, $selector);
  }

}
