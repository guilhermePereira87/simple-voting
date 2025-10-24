<?php

namespace Drupal\simple_voting\Entity;

/**
 * Defines the VotingQuestion entity.
 *
 * @ContentEntityType(
 *   id = "voting_question",
 *   labe
 *   base_table = "voting_question",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title",
 *     "owner" = "uid",
 *   },
 *   handlers = {
 *     "access" = "Drupal\simple_voting\Access\VotingQuestionControlHandler",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *      }
 *    },
 *  )
 */

class VotingQuestion extends ContentEntityBase implements EntityOwnerInterface, EntityChangedInterface {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = [];

    // Administrative label.
    $fields['label'] = baseFieldDefinitions::create('string')
      ->setLabel($this->t('Label'))
      ->setRequired(FALSE)
      ->setSettings(['max_length' => 255]);

    //Body of the question.
    $fields['question_text'] = baseFieldDefinitions::create('text_long')
      ->setLabel($this->t('Question Text'))
      ->setRequired(TRUE);

    // Show results.
    $fields['show_results'] = baseFieldDefinitions::create('boolean')
      ->setLabel($this->t('Show Results'))
      ->setDefaultValue(TRUE);

    // Question owner.
      $fields['uid'] = baseFieldDefinitions::create('entity_reference')
        ->setLabel($this->t('Author'))
        ->setSetting('target_type', 'user')
        ->setDefaultValueCallback([self::class, 'defaultUserId']);

    //Last updated.
      $fields['changed'] = baseFieldDefinitions::create('changed')
        ->setLavbel($this->t('Last Update'));

    return $fields;
  }

  /**
   * Callback for current user.
   */
  public static function defaultUserId(): array {
    $currentUser = \Drupal::currentUser();
    return [$currentUser->id()];
  }
}