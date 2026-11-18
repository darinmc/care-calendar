<?php
declare(strict_types=1);

/**
 * Reusable modal for viewing and adding to a thread (thoughts or messages).
 *
 * Required before include:
 *   $modalId          — unique ID for the modal element, e.g. 'modal-thoughts'
 *   $modalTitle       — heading shown at the top
 *   $modalSubtitle    — e.g. the date, or "for Louisa"
 *   $entries          — array of entries: each ['who' => ?, 'when' => DateTime, 'body' => string]
 *   $addAction        — URL the add form posts to
 *   $hiddenFields     — array of name => value for the add form
 *   $addLabel         — heading above the input box
 *   $addPlaceholder   — placeholder text for the input
 *   $addButton        — label for the save button
 *   $canAdd           — whether to show the add form at all
 *   $postKind         — 'note' or 'message'
 *
 * Optional:
 *   $modalUnreadDate  — YYYY-MM-DD; if set, opening the modal marks that day's messages as read
 *   $modalPastDay     — bool; if true, shows "can't add to past day" instead of the form
 */

$modalId         = $modalId         ?? 'modal';
$modalTitle      = $modalTitle      ?? '';
$modalSubtitle   = $modalSubtitle   ?? '';
$entries         = $entries         ?? [];
$addAction       = $addAction       ?? '';
$hiddenFields    = $hiddenFields    ?? [];
$addLabel        = $addLabel        ?? 'Add a new entry';
$addPlaceholder  = $addPlaceholder  ?? '';
$addButton       = $addButton       ?? 'Save';
$canAdd          = $canAdd          ?? false;
$postKind        = $postKind        ?? 'note';
$modalUnreadDate = $modalUnreadDate ?? '';
$modalPastDay    = $modalPastDay    ?? false;
?>
<div class="modal-backdrop" id="<?= e($modalId) ?>" hidden<?= $modalUnreadDate !== '' ? ' data-unread-date="' . e($modalUnreadDate) . '"' : '' ?>>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="<?= e($modalId) ?>-title">

        <header class="modal__header">
            <div>
                <h2 class="modal__title" id="<?= e($modalId) ?>-title"><?= e($modalTitle) ?></h2>
                <?php if ($modalSubtitle !== ''): ?>
                    <p class="modal__subtitle"><?= e($modalSubtitle) ?></p>
                <?php endif; ?>
            </div>
            <button type="button" class="modal__close"
                    data-modal-close="<?= e($modalId) ?>"
                    aria-label="Close">×</button>
        </header>

        <div class="modal__body">
            <?php if (empty($entries)): ?>
                <p class="modal__empty">Nothing here yet.</p>
            <?php else: ?>
                <ul class="modal__list">
                    <?php foreach ($entries as $entry): ?>
                        <li class="modal__item">
                            <?php if (!empty($entry['who'])): ?>
                                <span class="modal__who"><?= e($entry['who']) ?></span>
                            <?php endif; ?>
                            <span class="modal__when"><?= e($entry['when_label'] ?? $entry['when']->format('g:i a')) ?></span>
                            <div class="modal__text"><?= nl2br(e($entry['body'])) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($modalPastDay): ?>
            <div class="modal__past-note">
                This day has passed. You can read it, but you can't add to it.
            </div>
        <?php endif; ?>

        <?php if ($canAdd && !$modalPastDay): ?>
        <form class="modal__form" method="post" action="<?= e($addAction) ?>"
              data-modal-form="<?= e($modalId) ?>">
            <?= csrf_field() ?>
            <?php foreach ($hiddenFields as $name => $value): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e((string)$value) ?>">
            <?php endforeach; ?>

            <label class="modal__label" for="<?= e($modalId) ?>-input"><?= e($addLabel) ?></label>
            <textarea class="modal__textarea" id="<?= e($modalId) ?>-input"
                      name="note_body" rows="3"
                      placeholder="<?= e($addPlaceholder) ?>" required></textarea>

            <div class="modal__actions">
                <button type="submit" class="btn"><?= e($addButton) ?></button>
                <button type="button" class="btn btn--ghost modal__cancel"
                        style="background:var(--c-surface); color:var(--c-primary); border-color:var(--c-border);"
                        data-modal-close="<?= e($modalId) ?>">Cancel</button>
            </div>
        </form>
        <?php endif; ?>

    </div>
</div>
