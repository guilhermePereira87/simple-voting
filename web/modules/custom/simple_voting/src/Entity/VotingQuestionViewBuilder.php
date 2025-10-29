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
    // Load voting_option entities that reference this question.
    $option_entities = [];
    $option_storage = \Drupal::entityTypeManager()->getStorage('voting_option');
    $ids = $option_storage->getQuery()
      ->condition('question_id', $entity->id())
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->execute();
    if (!empty($ids)) {
      $option_entities = $option_storage->loadMultiple($ids);
    }

    // Determine whether the current user has voted on this question.
    $current_user = \Drupal::currentUser();
    $has_voted = FALSE;
    if ($current_user->isAuthenticated()) {
      $records = \Drupal::entityTypeManager()->getStorage('voting_record')->loadByProperties([
        'question_id' => $entity->id(),
        'uid' => $current_user->id(),
      ]);
      $has_voted = !empty($records);
    }

    // If the user hasn't voted, render the voting form (so they can vote).
    if (!$has_voted) {
      $build['vote_form'] = \Drupal::formBuilder()->getForm('Drupal\simple_voting\Form\VoteRecordForm', $entity);
      return $build;
    }

    // Determine whether to show results: only when the question setting is on.
    $show_results = ($entity->hasField('show_results') && $entity->get('show_results')->value);

    // If showing results, compute counts grouped by option_id.
    $counts = [];
    if ($show_results) {
      // Load voting_record entities for this question and group counts by
      // option_id. We use entity storage here to avoid DB driver-specific
      // chaining issues and keep the code robust.
      $record_storage = \Drupal::entityTypeManager()->getStorage('voting_record');
      $records = $record_storage->loadByProperties(['question_id' => $entity->id()]);
      foreach ($records as $rec) {
        // `option_id` is an entity reference base field; get its target id.
        $opt_ref = $rec->get('option_id');
        $opt_id = is_object($opt_ref) && isset($opt_ref->target_id) ? $opt_ref->target_id : $opt_ref->value ?? NULL;
        if ($opt_id !== NULL) {
          $counts[$opt_id] = ($counts[$opt_id] ?? 0) + 1;
        }
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
          $url = \Drupal::service('file_url_generator')->generateAbsoluteString($uri);
          $image_markup = '<img src="' . $url . '" alt="' . Xss::filterAdmin($title) . '" class="voting-option-image" />';
        }
      }

      // Option description if present.
      $description_markup = '';
      if ($opt->hasField('text') && !$opt->get('text')->isEmpty()) {
        $desc = $opt->get('text')->value;
        if (trim($desc) !== '') {
          $description_markup = '<div class="voting-option-description">' . Xss::filterAdmin($desc) . '</div>';
        }
      }

      $count_markup = '';
      if ($show_results) {
        $count = $counts[$opt->id()] ?? 0;
        $count_markup = '<span class="voting-option-count">' . $this->t('@count votes', ['@count' => $count]) . '</span>';
      }

      $options_items[] = [
        '#markup' => '<div class="voting-option">' . $image_markup . '<div class="voting-option-title">' . Xss::filterAdmin($title) . '</div>' . $description_markup . $count_markup . '</div>',
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
