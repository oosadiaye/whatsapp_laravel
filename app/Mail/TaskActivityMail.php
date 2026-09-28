<?php

declare(strict_types=1);

namespace App\Mail;

use App\Events\TaskBoardChanged;
use App\Models\Board;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a card's subscribers about a comment, an assignment change, a
 * description edit, or the card being deleted.
 *
 * Separate from TaskStatusChangedMail rather than a shared mailable with an
 * optional subject, because the two have genuinely different content: a move
 * has a from/to pair worth rendering, an activity event has a sentence about
 * who did what. Forcing both through one template produces a mail that is half
 * blank in one of its two cases.
 */
class TaskActivityMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly string $reason,
        public readonly ?Board $board = null,
        public readonly ?string $actorName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[%s] %s',
                $this->board?->name ?? 'Task Board',
                $this->subjectLine(),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.task-activity',
            with: [
                'task' => $this->task,
                'board' => $this->board,
                'reason' => $this->reason,
                'actorName' => $this->actorName ?? 'Someone',
                'summary' => $this->summary(),
                'statusLabel' => TaskStatus::label($this->task->status),
                'boardUrl' => $this->board !== null
                    ? route('boards.show', $this->board)
                    : route('dashboard'),
            ],
        );
    }

    private function subjectLine(): string
    {
        return match ($this->reason) {
            TaskBoardChanged::REASON_COMMENTED => sprintf('%s: new comment', $this->task->title),
            TaskBoardChanged::REASON_COMMENT_REMOVED => sprintf('%s: comment removed', $this->task->title),
            TaskBoardChanged::REASON_ASSIGNED => sprintf('%s: assignment changed', $this->task->title),
            TaskBoardChanged::REASON_UPDATED => sprintf('%s: description updated', $this->task->title),
            TaskBoardChanged::REASON_TRIAGE_UPDATED => sprintf('%s: deadline or priority changed', $this->task->title),
            TaskBoardChanged::REASON_DELETED => sprintf('%s: card deleted', $this->task->title),
            default => sprintf('%s: updated', $this->task->title),
        };
    }

    private function summary(): string
    {
        return match ($this->reason) {
            TaskBoardChanged::REASON_COMMENTED => 'left a comment',
            TaskBoardChanged::REASON_COMMENT_REMOVED => 'removed a comment',
            TaskBoardChanged::REASON_ASSIGNED => 'changed who this card is assigned to',
            TaskBoardChanged::REASON_UPDATED => 'updated the description',
            TaskBoardChanged::REASON_TRIAGE_UPDATED => 'changed the deadline, priority or estimate',
            TaskBoardChanged::REASON_DELETED => 'deleted this card',
            default => 'updated this card',
        };
    }
}
