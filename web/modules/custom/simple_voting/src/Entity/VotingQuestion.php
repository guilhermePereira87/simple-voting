<?php

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityOwnerInterface;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\simple_voting\Access\VotingQuestionAccessControlHandler;
use Drupal\simple_voting\Traits\EntityChangedTrait;

/**
 * Defines the VotingQuestion entity.
 *
 * @ContentEntityType(
 *   id = "voting_question",
 *   label = @Translation("Voting Question"),
 *   base_table = "voting_question",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title",
 *     "owner" = "uid",
 *   },
 *   handlers = {
 *     "access" = "Drupal\simple_voting\Access\VotingQuestionAccessControlHandler",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     }
 *   }
 * )
 */

class VotingQuestion extends ContentEntityBase implements EntityOwnerInterface, EntityChangedInterface {
  use StringTranslationTrait;
  use EntityPublishedTrait;
  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields =+ static::publishedBaseFieldDefinitions($entity_type);

    // Administrative label.
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(static::t('Title'))
      ->setRequired(FALSE)
      ->setSettings(['max_length' => 255])
      ->setDescription(static::t('Optional title for the question.'));

    //Body of the question.
    $fields['question_text'] = BaseFieldDefinition::create('text_long')
      ->setLabel(static::t('Question Text'))
      ->setRequired(TRUE)
      ->setDescription(static::t('Descriptor of the question.'));

    // Show results.
    $fields['show_results'] = BaseFieldDefinition::create('boolean')
      ->setLabel(static::t('Show Results'))
      ->setDefaultValue(TRUE)
      ->setDescription(static::t('Determines if the result of the pool will be displayed after vote.'));

    // Question owner.
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(static::t('Author'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback([self::class, 'defaultUserId'])
      ->setDescription(static::t('The owner of the question.'));

    //Last updated.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(static::t('Last Update'));

    return $fields;
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