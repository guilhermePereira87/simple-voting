<?php

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityOwnerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\simple_voting\Access\VotingQuestionAccessControlHandler;
use Drupal\simple_voting\Traits\EntityChangedTrait;
use Drupal\simple_voting\Traits\EntityOwnerTrait;
use Drupal\user\EntityOwnerInterface as UserEntityOwnerInterface;
use Drupal\user\UserInterface;

/**
 * Defines the VotingQuestion entity.
 *
 * @ContentEntityType(
 *   id = "voting_question",
 *   label = @Translation("Voting Question"),
 *   handlers = {
 *     "access" = "Drupal\simple_voting\Access\VotingQuestionAccessControlHandler",
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "translation" = "Drupal\content_translation\ContentTranslationHandler",
 *     "list_builder" = "Drupal\simple_voting\ListBuilder\VotingQuestionListBuilder",
 *     "view_builder" = "Drupal\simple_voting\Entity\VotingQuestionViewBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "add" = "Drupal\simple_voting\Form\VotingQuestionAddForm",
 *       "edit" = "Drupal\simple_voting\Form\VotingQuestionEditForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 *     },
 *   },
 *   links = {
 *     "canonical" = "/voting_question/{voting_question}",
 *     "add-form" = "/voting_question/add",
 *     "edit-form" = "/voting_question/{voting_question}/edit",
 *     "delete-form" = "/voting_question/{voting_question}/delete",
 *     "collection" = "/admin/content/voting_questions"
 *   },
 *   base_table = "voting_question",
 *   data_table = "voting_question_field_data",
 *   admin_permission = "administer voting questions",
 *   field_ui_base_route = "entity.voting_question.settings",
 *   translatable = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title",
 *     "owner" = "uid",
 *     "published" = "status",
 *     "langcode" = "langcode",
 *   }
 * )
 */

class VotingQuestion extends ContentEntityBase implements UserEntityOwnerInterface, EntityChangedInterface, EntityPublishedInterface {
  use EntityPublishedTrait;
  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::publishedBaseFieldDefinitions($entity_type);

    // Administrative label.
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setRequired(FALSE)
      ->setSettings(['max_length' => 255])
      ->setDescription(new TranslatableMarkup('Optional title for the question.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -100,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);


    // Body of the question.
    $fields['question_text'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Question Text'))
      ->setRequired(TRUE)
      ->setDescription(new TranslatableMarkup('Descriptor of the question.'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => -95,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Show results.
    $fields['show_results'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Show Results'))
      ->setDefaultValue(TRUE)
      ->setDescription(new TranslatableMarkup('Determines if the result of the pool will be displayed after vote.'))
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE);

    // Question owner.
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Author'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(self::class . '::defaultUserId')
      ->setDescription(new TranslatableMarkup('The owner of the question.'));

    //Last updated.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Last Update'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    parent::postDelete($storage, $entities);

    // Delete voting_option entities that reference the deleted question(s).
    $option_storage = \Drupal::entityTypeManager()->getStorage('voting_option');
    $to_delete = [];
    foreach ($entities as $entity) {
      $ids = $option_storage->getQuery()
        ->condition('question_id', $entity->id())
        ->accessCheck(FALSE)
        ->execute();
      if (!empty($ids)) {
        $to_delete = array_merge($to_delete, $option_storage->loadMultiple($ids));
      }
    }
    if (!empty($to_delete)) {
      $option_storage->delete($to_delete);
    }
  }

  /**
   * Callback for current user.
   *
   * @return array
   *   Returns current user ID.
   */
  public static function defaultUserId(): array {
    $currentUser = \Drupal::currentUser();
    return [$currentUser->id()];
  }

}