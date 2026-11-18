<?php
declare(strict_types=1);

/**
 * Renders the compact day-view thread box.
 *
 * Required before include:
 *   $threadKind      — 'thoughts' or 'messages'
 *   $threadTitle     — e.g. "My thoughts"
 *   $threadEntries   — array of entries [['who','when','body'], ...]
 *   $threadUnread    — int, unread count (only for messages)
 *   $threadCanAdd    — bool, whether to show the add link
 *   $threadAddLabel  — e.g. "+ Add a thought"
 *   $threadModalId   — DOM id for the modal, e.g. 'modal-thoughts'
 */

$threadKind     = $threadKind     ?? 'thoughts';
$threadTitle    = $threadTitle    ?? '';
$threadEntries  = $threadEntries  ?? [];
$threadUnread   = $threadUnread   ?? 0;
$threadCanAdd   = $threadCanAdd   ?? true;
$threadAddLabel = $threadAddLabel ?? '+ Add';
$threadModalId  = $threadModalId  ?? ('modal-' . $threadKind);
$threadModifier = ($threadKind === 'thoughts') ? 'thoughts' : 'messages';

$total   = count($threadEntries);
$shown   = array_slice($threadEntries, -2);
$hasMore = $total > 2;
?>
<section class="thread thread--<?= e($threadModifier) ?>">

    <div class="thread__header">
        <button type="button" class="thread__open"
                data-modal-open="<?= e($threadModalId) ?>"
                aria-label="Open <?= e($threadTitle) ?>">
            <span class="thread__icon" aria-hidden="true"><?= $threadKind === 'thoughts' ? '📝' : '💬' ?></span>
            <span class="thread__name"><?= e($threadTitle) ?></span>
            <?php if ($threadUnread > 0): ?>
                <span class="thread__unread"><?= (int)$threadUnread ?> new</span>
            <?php endif; ?>
        </button>

        <?php if ($threadCanAdd): ?>
            <button type="button" class="thread__add-link"
                    data-modal-open="<?= e($threadModalId) ?>"
                    data-modal-focus="1">
                <?= e($threadAddLabel) ?>
            </button>
        <?php endif; ?>
    </div>

    <button type="button" class="thread__open thread__open--body"
            data-modal-open="<?= e($threadModalId) ?>"
            aria-label="Open <?= e($threadTitle) ?>">
        <?php if (empty($shown)): ?>
            <span class="thread__empty">Nothing here yet.</span>
        <?php else: ?>
            <span class="thread__preview">
                <?php foreach ($shown as $entry): ?>
                    <span class="thread__row">
                        <?php if (!empty($entry['who'])): ?>
                            <span class="thread__who"><?= e($entry['who']) ?></span>
                        <?php endif; ?>
                        <span class="thread__time"><?= e($entry['when_label'] ?? $entry['when']->format('g:i a')) ?></span>
                        <span class="thread__body"><?= e($entry['body']) ?></span>
                    </span>
                <?php endforeach; ?>
            </span>
            <?php if ($hasMore): ?>
                <span class="thread__more">tap to see all <?= (int)$total ?></span>
            <?php endif; ?>
        <?php endif; ?>
    </button>

</section>
