<?php

namespace Tests\Feature;

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
 * Images posted with a comment are processed like issue attachments and belong to that comment.
 */
class CommentImageTest extends TestCase
{
    use BuildsCompanies;
    use RefreshDatabase;

    private User $manager;
    private User $staff;
    private Project $project;
    private Report $issue;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        [$company, $this->manager] = $this->makeCompany('Acme');
        $this->project = $this->makeProject($company, 'Website');
        $this->staff = $this->makeStaff($company, [$this->project]);
        $this->issue = $this->makeIssue($this->project, $this->staff);
    }

    private function comment(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->post('/api/reports/' . $this->issue->uuid, [
            '_method' => 'PATCH',
            'action' => 'add_comment',
            ...$data,
        ], ['Accept' => 'application/json']);
    }

    public function test_a_comment_can_carry_images()
    {
        $this->comment([
            'comment' => 'Still broken, see these',
            'images' => [UploadedFile::fake()->image('one.png', 3000, 1500), UploadedFile::fake()->image('two.jpg', 100, 100)],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'report.comments.0.attachments')
            ->assertJsonPath('report.comments.0.attachments.0.original_filename', 'one.webp')
            // The issue's own attachments do not include the comment's images
            ->assertJsonCount(0, 'report.attachments');

        $comment = ReportComment::sole();
        $this->assertSame('Still broken, see these', $comment->comment);
        $this->assertCount(2, $comment->attachments);
        $this->assertTrue($comment->attachments->every(fn (ReportAttachment $a) => $a->report_id === $this->issue->id));
        $this->assertCount(2, Storage::disk('public')->allFiles());

        // Scaled down like any other image
        $info = getimagesizefromstring(Storage::disk('public')->get($comment->attachments->first()->storage_path));
        $this->assertSame([2000, 1000, IMAGETYPE_WEBP], [$info[0], $info[1], $info[2]]);
    }

    public function test_a_comment_can_be_only_images()
    {
        $this->comment(['images' => [UploadedFile::fake()->image('shot.png', 100, 100)]])->assertOk();

        $comment = ReportComment::sole();
        $this->assertSame('', $comment->comment);
        $this->assertSame('Added 1 image.', $comment->summary());
    }

    public function test_a_comment_needs_text_or_an_image()
    {
        $this->comment(['comment' => '   '])->assertStatus(422)->assertJsonValidationErrors('comment');

        $this->assertSame(0, ReportComment::count());
    }

    public function test_text_comments_still_work_as_json()
    {
        $this->actingAs($this->manager)
            ->patchJson('/api/reports/' . $this->issue->uuid, ['action' => 'add_comment', 'comment' => 'On it'])
            ->assertOk()
            ->assertJsonPath('report.comments.0.comment', 'On it');
    }

    public function test_only_images_may_be_posted_with_a_comment()
    {
        $pdf = UploadedFile::fake()->createWithContent('spec.pdf', "%PDF-1.4\n%%EOF\n");

        $this->comment(['comment' => 'Spec attached', 'images' => [$pdf]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images.0');

        $this->assertSame(0, ReportComment::count());
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_a_bad_image_saves_neither_the_comment_nor_any_file()
    {
        $fake = UploadedFile::fake()->createWithContent('photo.png', "<?php echo 'not an image';");

        $this->comment(['comment' => 'Look', 'images' => [UploadedFile::fake()->image('ok.png', 50, 50), $fake]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images' => '"photo.png" is not a valid JPEG, PNG, GIF or WebP image.']);

        $this->assertSame(0, ReportComment::count());
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_at_most_five_images_per_comment()
    {
        $images = array_map(fn ($i) => UploadedFile::fake()->image("{$i}.png", 10, 10), range(1, 6));

        $this->comment(['images' => $images])->assertStatus(422)->assertJsonValidationErrors('images');
    }

    public function test_deleting_the_issue_removes_comment_images_too()
    {
        $this->comment(['images' => [UploadedFile::fake()->image('shot.png', 100, 100)]])->assertOk();
        $this->assertCount(1, Storage::disk('public')->allFiles());

        $this->actingAs($this->manager)->deleteJson('/api/reports/' . $this->issue->uuid)->assertOk();

        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->assertSame(0, ReportAttachment::count());
    }

    public function test_people_who_cannot_comment_cannot_post_images()
    {
        [, $outsideManager] = $this->makeCompany('Borneo');

        $this->comment(['comment' => 'Hi', 'images' => [UploadedFile::fake()->image('x.png', 10, 10)]], $outsideManager)
            ->assertForbidden();

        $this->assertCount(0, Storage::disk('public')->allFiles());
    }
}
