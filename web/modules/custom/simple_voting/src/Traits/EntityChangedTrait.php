<?php

namespace Drupal\simple_voting\Traits;

/**
 * Provides shared implementation for EntityChangedInterface methods.
 */
trait EntityChangedTrait {
  /**
   * {@inheritdoc}
   */
  public function getChangedTime(): int{
    return (int) $this->get('changed')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setChangedTime($timestamp): static {
    $this->set('changed', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getChangedTimeAcrossTranslations(): int {
  return (int) $this->getUntranslated()->get('changed')->value;
  }
}