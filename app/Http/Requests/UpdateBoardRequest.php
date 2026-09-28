<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateBoardRequest extends StoreBoardRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('tasks.edit');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $boardId = $this->route('board')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            // Ignore this board's own slug so renaming doesn't collide with itself.
            'slug' => [
                'nullable', 'string', 'max:255', 'alpha_dash',
                Rule::unique('boards', 'slug')->where('user_id', $this->user()->id)->ignore($boardId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
