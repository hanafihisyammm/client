<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportAttachment;
use App\Models\ReportComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

/**
 * Admins and Super Admins may edit or delete any comment; nobody else may.
 */
class CommentManagementTest extends TestCase
{
    use BuildsCompanies;
    use RefreshDatabase;

    private User $manager;
    private User $staff;
    private Project $project;
    private Report $issue;
    private ReportComment $comment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        [$company, $this->manager] = $this->makeCompany('Acme');
        $this->project = $this->makeProject($company, 'Website');
        $this->staff = $this->makeStaff($company, [$this->project]);
        $this->issue = $this->makeIssue($this->project, $this->staff);
        $this->comment = ReportComment::create([
            'report_id' => $this->issue->id,
            'user_id' => $this->staff->id,
            'comment' => 'Original text',
        ]);
    }

    public function test_admins_and_super_admins_can_edit_any_comment()
    {
        foreach (['admin', 'super_admin'] as $role) {
            $this->act($this->makeAdmin($role), $this->issue, [
                'action' => 'edit_comment',
                'comment_id' => $this->comment->id,
                'comment' => "Edited by $role",
            ])
                ->assertOk()
                ->assertJsonPath('message', 'Comment updated successfully.')
                ->assertJsonPath('report.comments.0.comment', "Edited by $role")
                ->assertJsonPath('abilities.manage_comments', true);
        }

        $this->assertTrue(AuditLog::where('action', 'comment.updated')->where('subject_id', $this->comment->id)->exists());
    }

    public function test_admins_and_super_admins_can_delete_any_comment()
    {
        foreach (['admin', 'super_admin'] as $role) {
            $comment = ReportComment::create(['report_id' => $this->issue->id, 'user_id' => $this->staff->id, 'comment' => 'Bye']);

            $this->act($this->makeAdmin($role), $this->issue, ['action' => 'delete_comment', 'comment_id' => $comment->id])
                ->assertOk()
                ->assertJsonPath('message', 'Comment deleted successfully.');

            $this->assertDatabaseMissing('report_comments', ['id' => $comment->id]);
            $this->assertTrue(AuditLog::where('action', 'comment.deleted')->where('subject_id', $comment->id)->exists());
        }
    }

    public function test_managers_and_staff_cannot_edit_or_delete_comments_even_their_own()
    {
        foreach ([$this->manager, $this->staff] as $user) {
            $this->act($user, $this->issue, ['action' => 'edit_comment', 'comment_id' => $this->comment->id, 'comment' => 'Changed'])
                ->assertForbidden();
            $this->act($user, $this->issue, ['action' => 'delete_comment', 'comment_id' => $this->comment->id])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('report_comments', ['id' => $this->comment->id, 'comment' => 'Original text']);
    }

    public function test_only_admins_are_told_they_can_manage_comments()
    {
        $this->actingAs($this->manager)->getJson('/api/reports/' . $this->issue->uuid)
            ->assertJsonPath('abilities.manage_comments', false);
        $this->actingAs($this->makeAdmin())->getJson('/api/reports/' . $this->issue->uuid)
            ->assertJsonPath('abilities.manage_comments', true);
    }

    public function test_a_comment_on_another_issue_cannot_be_reached_through_this_one()
    {
        $other = $this->makeIssue($this->project, $this->staff);

        $this->act($this->makeAdmin(), $other, ['action' => 'delete_comment', 'comment_id' => $this->comment->id])
            ->assertNotFound();

        $this->assertDatabaseHas('report_comments', ['id' => $this->comment->id]);
    }

    public function test_a_comment_without_files_cannot_be_emptied()
    {
        $this->act($this->makeAdmin(), $this->issue, ['action' => 'edit_comment', 'comment_id' => $this->comment->id, 'comment' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('comment');
    }

    public function test_deleting_a_comment_removes_its_files()
    {
        $path = UploadedFile::fake()->image('shot.png', 10, 10)->store('report-attachments', 'public');
        $attachment = ReportAttachment::create([
            'report_id' => $this->issue->id,
            'report_comment_id' => $this->comment->id,
            'original_filename' => 'shot.png',
            'filename' => basename($path),
            'mime_type' => 'image/png',
            'file_size' => 100,
            'storage_path' => $path,
        ]);

        $this->act($this->makeAdmin(), $this->issue, ['action' => 'delete_comment', 'comment_id' => $this->comment->id])
            ->assertOk();

        $this->assertDatabaseMissing('report_attachments', ['id' => $attachment->id]);
        Storage::disk('public')->assertMissing($path);
    }
}
