<?php

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\Xss;

/**
 * Custom view builder for VotingQuestion.
 */
class VotingQuestionViewBuilder extends EntityViewBuilder {

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $entity, $view_mode = 'full', $langcode = NULL) {
    $build = parent::view($entity, $view_mode, $langcode);

    // Only add options to full view modes (avoid polluting teaser etc.).
    if ($view_mode !== 'full') {
      return $build;
    }

    /* @var \Drupal\simple_voting\Entity\VotingQuestion $entity */
    // Prepare options render array.
    $options_items = [];
    $option_entities = [];
    if ($entity->hasField('choice')) {
      $option_entities = $entity->get('choice')->referencedEntities();
    }

    // Determine whether to show results: show_results must be enabled and the
    // current user must have voted on this question (VotingRecord exists).
    $show_results = FALSE;
    if ($entity->hasField('show_results') && $entity->get('show_results')->value) {
      $current_user = \Drupal::currentUser();
      if ($current_user->isAuthenticated()) {
        $records = \Drupal::entityTypeManager()->getStorage('voting_record')->loadByProperties([
          'question_id' => $entity->id(),
          'uid' => $current_user->id(),
        ]);
        if (!empty($records)) {
          $show_results = TRUE;
        }
      }
    }

    // If showing results, compute counts grouped by option_id.
    $counts = [];
    if ($show_results) {
      $db = \Drupal::database();
      $res = $db->select('voting_record', 'vr')
        ->fields('vr', ['option_id'])
        ->condition('vr.question_id', $entity->id())
        ->addExpression('COUNT(vr.id)', 'count')
        ->groupBy('vr.option_id')
        ->execute();
      foreach ($res as $row) {
        $counts[$row->option_id] = (int) $row->count;
      }
    }

    foreach ($option_entities as $opt) {
      $title = $opt->label();

      // Image markup if present.
      $image_markup = '';
      if ($opt->hasField('image') && !$opt->get('image')->isEmpty()) {
        $fid = $opt->get('image')->target_id;
        $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
        if ($file) {
          $uri = $file->getFileUri();
          $url = file_create_url($uri);
          $image_markup = '<img src="' . $url . '" alt="' . Xss::filterAdmin($title) . '" class="voting-option-image" />';
        }
      }

      $count_markup = '';
      if ($show_results) {
        $count = $counts[$opt->id()] ?? 0;
        $count_markup = '<span class="voting-option-count">' . $this->t('@count votes', ['@count' => $count]) . '</span>';
      }

      $options_items[] = [
        '#markup' => '<div class="voting-option">' . $image_markup . '<div class="voting-option-title">' . Xss::filterAdmin($title) . '</div>' . $count_markup . '</div>',
      ];
    }

    if (!empty($options_items)) {
      $build['options'] = [
        '#theme' => 'item_list',
        '#items' => $options_items,
        '#attributes' => ['class' => ['voting-question-options']],
        '#weight' => 10,
      ];
    }

    return $build;
  }

}
