<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Board;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells one person a card was just handed to them.
 *
 * Deliberately not a flavour of TaskActivityMail: that one is a sentence about
 * what changed, sent to everyone on the card who asked for all activity. This
 * one is addressed to the new assignee alone and says what they have to do —
 * which card, who gave it to them, when it is due — because it is the message
 * that has to make someone start work.
 */
class TaskAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly ?Board $board = null,
        public readonly ?string $assignerName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('%s assigned you a task: %s', $this->assigner(), $this->task->title),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.task-assigned',
            with: [
                'task' => $this->task,
                'board' => $this->board,
                'assigner' => $this->assigner(),
                'statusLabel' => TaskStatus::label($this->task->status),
                'priorityLabel' => $this->task->priorityLabel(),
                'myTasksUrl' => route('tasks.mine'),
                'boardUrl' => $this->board !== null
                    ? route('boards.show', $this->board)
                    : route('tasks.mine'),
            ],
        );
    }

    private function assigner(): string
    {
        return $this->assignerName ?: 'Someone';
    }
}
