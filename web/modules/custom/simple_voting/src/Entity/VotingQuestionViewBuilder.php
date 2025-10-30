<?php

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Component\Utility\Xss;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Custom view builder for VotingQuestion.
 */
class VotingQuestionViewBuilder extends EntityViewBuilder {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * File URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    // Inject commonly used services to avoid Drupal:: calls inside view().
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->formBuilder = $container->get('form_builder');
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $entity, $view_mode = 'full', $langcode = NULL) {
    $build = parent::view($entity, $view_mode, $langcode);

    // Only add options to full view modes (avoid polluting teaser etc.).
    if ($view_mode !== 'full') {
      return $build;
    }

    /** @var \Drupal\simple_voting\Entity\VotingQuestion $entity */
    // Prepare options render array.
    $optionsItems = [];
    // Load voting_option entities that reference this question.
    $optionEntities = [];
    $optionStorage = $this->entityTypeManager->getStorage('voting_option');
    $ids = $optionStorage->getQuery()
      ->condition('question_id', $entity->id())
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->execute();
    if (!empty($ids)) {
      $optionEntities = $optionStorage->loadMultiple($ids);
    }

    // Determine whether the current user has voted on this question.
    $currentUser = $this->currentUser;
    $hasVoted = FALSE;
    if ($currentUser->isAuthenticated()) {
      $recordStorage = $this->entityTypeManager->getStorage('voting_record');
      $records = $recordStorage->loadByProperties([
        'question_id' => $entity->id(),
        'uid' => $currentUser->id(),
      ]);
      $hasVoted = !empty($records);
    }

    // If the user hasn't voted, render the voting form (so they can vote).
    if (!$hasVoted) {
      $build['vote_form'] = $this->formBuilder->getForm('Drupal\simple_voting\Form\VoteRecordForm', $entity);
      return $build;
    }

    // Determine whether to show results: only when the question setting is on.
    $showResults = ($entity->hasField('show_results') && $entity->get('show_results')->value);

    // If showing results, compute counts grouped by option_id.
    $counts = [];
    if ($showResults) {
      // Load voting_record entities for this question and group counts by
      // option_id. We use entity storage here to avoid DB driver-specific
      // chaining issues and keep the code robust.
      $recordStorage = $this->entityTypeManager->getStorage('voting_record');
      $records = $recordStorage->loadByProperties(['question_id' => $entity->id()]);
      foreach ($records as $rec) {
        // `option_id` is an entity reference base field; get its target id.
        $optRef = $rec->get('option_id');
        $optId = is_object($optRef) && isset($optRef->target_id) ? $optRef->target_id : $optRef->value ?? NULL;
        if ($optId !== NULL) {
          $counts[$optId] = ($counts[$optId] ?? 0) + 1;
        }
      }
    }

    foreach ($optionEntities as $optEntity) {
      $title = $optEntity->label();

      // Image markup if present.
      $imageMarkup = '';
      if ($optEntity->hasField('image') && !$optEntity->get('image')->isEmpty()) {
        $fid = $optEntity->get('image')->target_id;
        $file = $this->entityTypeManager->getStorage('file')->load($fid);
        if ($file) {
          $uri = $file->getFileUri();
          $url = $this->fileUrlGenerator->generateAbsoluteString($uri);
          $imageMarkup = '<img src="' . $url . '" alt="' . Xss::filterAdmin($title) . '" class="voting-option-image" />';
        }
      }

      // Option description if present.
      $descriptionMarkup = '';
      if ($optEntity->hasField('text') && !$optEntity->get('text')->isEmpty()) {
        $desc = $optEntity->get('text')->value;
        if (trim($desc) !== '') {
          $descriptionMarkup = '<div class="voting-option-description">' . Xss::filterAdmin($desc) . '</div>';
        }
      }

      $countMarkup = '';
      if ($showResults) {
        $count = $counts[$optEntity->id()] ?? 0;
        $countMarkup = '<span class="voting-option-count">' . $this->t('@count votes', ['@count' => $count]) . '</span>';
      }

      $optionsItems[] = [
        '#markup' => '<div class="voting-option">' . $imageMarkup . '<div class="voting-option-title">' . Xss::filterAdmin($title) . '</div>' . $descriptionMarkup . $countMarkup . '</div>',
      ];
    }

    if (!empty($optionsItems)) {
      $build['options'] = [
        '#theme' => 'item_list',
        '#items' => $optionsItems,
        '#attributes' => ['class' => ['voting-question-options']],
        '#weight' => 10,
      ];
    }

    return $build;
  }

}
