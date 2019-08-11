<?php

namespace Drupal\picture_background_formatter\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\image\Entity\ImageStyle;
use Drupal\responsive_image\Entity\ResponsiveImageStyle;
use Drupal\responsive_image\Plugin\Field\FieldFormatter\ResponsiveImageFormatter;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\picture_background_formatter\Component\Render\CSSSnippet;

/**
 * Plugin implementation of the 'picture_background_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "picture_background_formatter",
 *   label = @Translation("Picture background formatter"),
 *   field_types = {
 *     "image"
 *   }
 * )
 */
class PictureBackgroundFormatter extends ResponsiveImageFormatter implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
        'selector' => '',
        'picture_background_image_output_type' => 'inline',
      ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);
    unset($elements['image_link']);

    $token_types = [$this->fieldDefinition->getTargetEntityTypeId()];

    $elements['selector'] = [
      '#type' => 'textfield',
      '#title' => t('Selector'),
      '#required' => TRUE,
      '#description' => t('CSS Selector for background image.'),
      '#default_value' => $this->getSetting('selector'),
      '#token_types' => $token_types,
      '#element_validate' => 'token_element_validate',
    ];

    $element['picture_background_image_output_type'] = [
      '#title' => $this->t('Output To'),
      '#type' => 'select',
      '#options' => [
        'inline' => $this->t('Write background-image to inline style attribute'),
        'css' => $this->t('Write background-image to CSS selector'),
      ],
      '#default_value' => $this->getSetting('picture_background_image_output_type'),
      '#required' => TRUE,
      '#description' => $this->t('Define how background-image will be printed to the dom.'),
    ];


    $elements['tokens'] = [
      '#theme' => 'token_tree_link',
      '#token_types' => $token_types,
      '#global_types' => TRUE,
      '#show_nested' => FALSE,
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $responsive_image_style = $this->responsiveImageStyleStorage->load($this->getSetting('responsive_image_style'));
    if ($responsive_image_style) {
      $summary[] = t('Responsive image style: @responsive_image_style', ['@responsive_image_style' => $responsive_image_style->label()]);
    }
    else {
      $summary[] = t('Select a responsive image style.');
    }

    $summary[] = $this->t('Output type: @output_type', ['@output_type' => $this->getSetting('picture_background_image_output_type')]);

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    $entity = $items->getEntity();

    // Load the files to render.
    $files = $this->getEntitiesToView($items, $langcode);

    // Early opt-out if the field is empty.
    if (empty($files)) {
      return $elements;
    }

    return $this->build_element($files, $entity);
  }

  /**
   * Build the inline css style based on a set of files and a selector.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $files
   *   The array of referenced files to display, keyed by delta.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The parent entity the field belongs to. Used for token replacement in the
   *   selector.
   *
   * @return array
   */
  protected function build_element($files, $entity) {

    // Collect cache tags to be added for each item in the field.
    $responsive_image_style = $this->responsiveImageStyleStorage->load($this->getSetting('responsive_image_style'));
    $image_styles_to_load = [];
    $cache_tags = [];
    if ($responsive_image_style) {
      $cache_tags = Cache::mergeTags($cache_tags, $responsive_image_style->getCacheTags());
      $image_styles_to_load = $responsive_image_style->getImageStyleIds();
    }

    $image_styles = $this->imageStyleStorage->loadMultiple($image_styles_to_load);
    foreach ($image_styles as $image_style) {
      $cache_tags = Cache::mergeTags($cache_tags, $image_style->getCacheTags());
    }

    return $this->markupFile($files, $entity, $responsive_image_style);
  }

  protected function markupFile($files, $entity, $responsive_image_style) {
    $elements = [];

    $selector = $this->getSetting('selector');
    $selector = \Drupal::token()->replace($selector, [$entity->getEntityTypeId() => $entity], ['clear' => TRUE]);
    $id = 'picture-background-formatter-' . $selector;

    switch ($this->getSetting('picture_background_image_output_type')) {
      case 'css':
        $elements = [];
        $css = "";

        foreach ($files as $file) {
          $css .= $this->generate_background_css($file, $responsive_image_style, $selector);
        }
        $cssSnippet = new CSSSnippet($css);  // Use __toString()?
        $data =  [[
          '#tag' => 'style',
          '#value' => $cssSnippet,
        ], $id];

        $elements['#attached']['html_head'][] = $data;

        break;

      case 'inline':

        $theme = [
          '#picture_backgrouned_image_selector' => $id,
        ];
        $theme['#theme'] = 'picture_background_image_formatter_inline';

        foreach ($files as  $delta => $file) {
          if (empty($file)) {
            continue;
          }

          $markup_string = "<div class=\"". $id . "\" style=\"background-image: url('" . $file->createFileUrl() . "');\">
  &nbsp;
</div>";

          $markup = Markup::create($markup_string); //->create();
          // =  $selector . ' {background-image: url(' . $url . ');}';
          $theme['#image_uri'] = $file->createFileUrl();
          $elements[$delta] = [
            '#markup' => $markup,
          ];
        }
        //; //\Drupal::service('renderer')->render($theme),

        break;
    }

    return $elements;
  }

  /**
   * CSS Generator Helper Function.
   *
   * @param ImageItem $image
   *   URI of the field image.
   * @param array $responsive_image_style
   *   Desired picture mapping to generate CSS.
   * @param string $selector
   *   CSS selector to target.
   *
   * @return string
   *   Generated background image CSS.
   *
   */
  protected function generate_background_css($image, $responsive_image_style, $selector) {
    $css = "";
    if (empty($image)) {
      \Drupal::logger('background_picture_formatter')
        ->notice("Background image is not set.");
      return $css;
    }

    $breakpoints = \Drupal::service('breakpoint.manager')
      ->getBreakpointsByGroup($responsive_image_style->getBreakpointGroup());
    foreach (array_reverse($responsive_image_style->getKeyedImageStyleMappings()) as $breakpoint_id => $multipliers) {
      if (isset($breakpoints[$breakpoint_id])) {

        $multipliers = array_reverse($multipliers);

        $query = $breakpoints[$breakpoint_id]->getMediaQuery();
        if ($query != "") {
          $css .= ' @media ' . $query . ' {';
        }

        foreach ($multipliers as $multiplier => $mapping) {
          $multiplier = rtrim($multiplier, "x");

          if ($mapping['image_mapping_type'] != 'image_style') {
            continue;
          }

          if ($mapping['image_mapping'] == "_original image_") {
            $url = file_create_url($image->getFileUri());
          }
          else {
            $url = ImageStyle::load($mapping['image_mapping'])
              ->buildUrl($image->getFileUri());
          }

          if ($multiplier != 1) {
            $css .= ' @media (-webkit-min-device-pixel-ratio: ' . $multiplier . '), (min-resolution: ' . $multiplier * 96 . 'dpi), (min-resolution: ' . $multiplier . 'dppx) {';
          }

          $css .= $selector . ' {background-image: url(' . $url . ');}';

          if ($multiplier != 1) {
            $css .= '}';
          }
        }

        if ($query != "") {
          $css .= '}';
        }
      }
    }

    return $css;
  }
}
