<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-user notification level on the profile form.
 *
 * Worth its own file because the field is wired into a shared Jetstream-style
 * request used by every profile edit - a bad rule there would either throw on
 * unrelated saves or quietly reset the preference.
 */
class ProfileNotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'task_notifications' => User::TASK_NOTIFICATIONS_TRANSITIONS,
        ]);
    }

    private function profilePayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
        ], $overrides);
    }

    public function test_a_user_can_move_their_own_notification_level(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->patch('/profile', $this->profilePayload($user, [
            'task_notifications' => User::TASK_NOTIFICATIONS_ALL,
        ]))->assertRedirect(route('profile.edit'));

        $this->assertSame(User::TASK_NOTIFICATIONS_ALL, $user->fresh()->task_notifications);
    }

    public function test_an_invalid_level_is_rejected(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->patch('/profile', $this->profilePayload($user, [
            'task_notifications' => 'everything-always',
        ]))->assertSessionHasErrors('task_notifications');

        $this->assertSame(
            User::TASK_NOTIFICATIONS_TRANSITIONS,
            $user->fresh()->task_notifications,
            'a rejected value must not be written'
        );
    }

    public function test_omitting_the_field_leaves_the_current_level_alone(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['task_notifications' => User::TASK_NOTIFICATIONS_ALL])->save();
        $this->actingAs($user);

        // A client that only posts name/email (the old form, an API caller)
        // must not silently reset an explicit choice back to the default.
        $this->patch('/profile', $this->profilePayload($user))
            ->assertSessionHasNoErrors();

        $this->assertSame(User::TASK_NOTIFICATIONS_ALL, $user->fresh()->task_notifications);
    }

    public function test_the_profile_page_offers_the_three_levels(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->get('/profile')
            ->assertOk()
            ->assertSee('name="task_notifications"', escape: false)
            ->assertSee('Column moves only', escape: false)
            ->assertSee('All activity', escape: false);
    }
}
