<?php

declare(strict_types=1);

namespace App\Mail;

use App\Listeners\SendTaskStatusEmail;
use App\Models\Board;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time notification that a task moved between board columns.
 *
 * Sent by {@see SendTaskStatusEmail} on the queue so a slow SMTP
 * handshake never blocks the Livewire drag-and-drop request that triggered the
 * status change.
 */
class TaskStatusChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly ?Board $board = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[%s] %s moved to %s',
                $this->board?->name ?? 'Task Board',
                $this->task->title,
                $this->newStatusLabel(),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.task-status-changed',
            with: [
                'task' => $this->task,
                'board' => $this->board,
                'oldStatus' => $this->oldStatus,
                'oldStatusLabel' => $this->statusLabel($this->oldStatus),
                'newStatus' => $this->newStatus,
                'newStatusLabel' => $this->newStatusLabel(),
                'boardUrl' => $this->board !== null
                    ? route('boards.show', $this->board)
                    : route('dashboard'),
            ],
        );
    }

    private function newStatusLabel(): string
    {
        return TaskStatus::label($this->newStatus);
    }

    private function statusLabel(string $key): string
    {
        return TaskStatus::label($key);
    }
}
