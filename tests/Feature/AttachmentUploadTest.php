<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Report;
use App\Models\ReportAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

/**
 * Images attached to issues are checked, scaled down and stored as WebP.
 * PDFs and videos are stored exactly as uploaded.
 */
class AttachmentUploadTest extends TestCase
{
    use BuildsCompanies;
    use RefreshDatabase;

    /** A two-frame, 1x1 animated GIF */
    private const ANIMATED_GIF = 'R0lGODlhAQABAIAAAP8AAAAAACH/C05FVFNDQVBFMi4wAwEAAAAh+QQACgAAACwAAAAAAQABAAACAkQBACH5BAAKAAAALAAAAAABAAEAAAICTAEAOw==';

    private User $manager;
    private User $staff;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        [$company, $this->manager] = $this->makeCompany('Acme');
        $this->project = $this->makeProject($company, 'Website');
        $this->staff = $this->makeStaff($company, [$this->project]);
    }

    private function createIssueWith(array $files, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff)->post('/api/reports', [
            'title' => 'Checkout is broken',
            'description' => 'See the screenshots',
            'page_urls' => ['https://example.com/checkout'],
            'project_id' => $this->project->id,
            'priority' => 'high',
            'attachments' => $files,
        ], ['Accept' => 'application/json']);
    }

    private function addTo(Report $issue, array $files, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->post('/api/reports/' . $issue->uuid, [
            '_method' => 'PATCH',
            'action' => 'add_attachments',
            'attachments' => $files,
        ], ['Accept' => 'application/json']);
    }

    /** [width, height, image type] of a stored file, read from its real content. */
    private function storedImage(ReportAttachment $attachment): array
    {
        $info = getimagesizefromstring(Storage::disk('public')->get($attachment->storage_path));

        return [$info[0], $info[1], $info[2]];
    }

    private function pdf(string $name = 'spec.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    /**
     * An upload whose type is judged from its content, as a real upload's is (fake uploads go by
     * their name alone).
     */
    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** A file whose content really is a video of the given kind, padded to $kilobytes */
    private function video(string $name, string $kind = 'mp4', int $kilobytes = 1): UploadedFile
    {
        $header = match ($kind) {
            'mp4' => "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom",
            'mov' => "\x00\x00\x00\x14ftypqt  \x00\x00\x00\x00qt  ",
            'webm' => "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02",
        };

        return $this->realUpload($name, str_pad($header, $kilobytes * 1024, "\0"));
    }

    // ------------------------------------------------------------------ Conversion and resizing

    public function test_a_large_image_is_scaled_down_and_stored_as_webp()
    {
        $this->createIssueWith([UploadedFile::fake()->image('Home Page.png', 3000, 1500)])->assertCreated();

        $attachment = ReportAttachment::firstOrFail();

        // Longest side brought down to the limit, proportions kept (2:1), real WebP content
        $this->assertSame([2000, 1000, IMAGETYPE_WEBP], $this->storedImage($attachment));

        $this->assertSame('image/webp', $attachment->mime_type);
        $this->assertStringEndsWith('.webp', $attachment->filename);
        $this->assertSame('reports/' . $attachment->report_id . '/' . $attachment->filename, $attachment->storage_path);
        $this->assertSame('Home Page.webp', $attachment->original_filename);
        $this->assertSame(Storage::disk('public')->size($attachment->storage_path), $attachment->file_size);

        // Only the processed file is kept, not the original upload as well
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_a_tall_image_keeps_its_proportions_and_is_not_cropped()
    {
        $this->createIssueWith([UploadedFile::fake()->image('long-page.jpg', 1000, 4000)])->assertCreated();

        $this->assertSame([500, 2000, IMAGETYPE_WEBP], $this->storedImage(ReportAttachment::firstOrFail()));
    }

    public function test_a_small_image_is_converted_but_never_enlarged()
    {
        $this->createIssueWith([UploadedFile::fake()->image('icon.jpg', 320, 200)])->assertCreated();

        $this->assertSame([320, 200, IMAGETYPE_WEBP], $this->storedImage(ReportAttachment::firstOrFail()));
    }

    public function test_every_allowed_image_format_becomes_webp()
    {
        $webp = tempnam(sys_get_temp_dir(), 'webp');
        imagewebp(imagecreatetruecolor(640, 480), $webp);

        $this->createIssueWith([
            UploadedFile::fake()->image('a.jpg', 640, 480),
            UploadedFile::fake()->image('b.png', 640, 480),
            UploadedFile::fake()->image('c.gif', 640, 480),
            new UploadedFile($webp, 'd.webp', 'image/webp', null, true),
        ])->assertCreated();

        $attachments = ReportAttachment::orderBy('id')->get();
        $this->assertSame(['a.webp', 'b.webp', 'c.webp', 'd.webp'], $attachments->pluck('original_filename')->all());

        foreach ($attachments as $attachment) {
            $this->assertSame([640, 480, IMAGETYPE_WEBP], $this->storedImage($attachment));
        }
    }

    public function test_an_animated_gif_is_kept_as_it_is_so_it_still_plays()
    {
        $bytes = base64_decode(self::ANIMATED_GIF);

        $this->createIssueWith([UploadedFile::fake()->createWithContent('steps.gif', $bytes)])->assertCreated();

        $attachment = ReportAttachment::firstOrFail();
        $this->assertSame(['image/gif', 'steps.gif'], [$attachment->mime_type, $attachment->original_filename]);
        $this->assertSame($bytes, Storage::disk('public')->get($attachment->storage_path));
    }

    // ------------------------------------------------------------------ Non-image uploads

    public function test_a_pdf_is_stored_exactly_as_uploaded()
    {
        $pdf = $this->pdf('Requirements v2.pdf');
        $original = file_get_contents($pdf->getRealPath());

        $this->createIssueWith([$pdf])->assertCreated();

        $attachment = ReportAttachment::firstOrFail();
        $this->assertSame(['application/pdf', 'Requirements v2.pdf'], [$attachment->mime_type, $attachment->original_filename]);
        $this->assertStringEndsWith('.pdf', $attachment->storage_path);
        $this->assertSame($original, Storage::disk('public')->get($attachment->storage_path));
    }

    public function test_images_and_pdfs_can_be_uploaded_together()
    {
        $this->createIssueWith([UploadedFile::fake()->image('shot.png', 800, 600), $this->pdf()])->assertCreated();

        $this->assertEqualsCanonicalizing(['image/webp', 'application/pdf'], ReportAttachment::pluck('mime_type')->all());
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    // ------------------------------------------------------------------ Videos

    public function test_mp4_webm_and_mov_videos_are_stored_exactly_as_uploaded()
    {
        $videos = [$this->video('Checkout bug.mp4'), $this->video('steps.webm', 'webm'), $this->video('Screen Recording.mov', 'mov')];
        $originals = array_map(fn ($video) => file_get_contents($video->getRealPath()), $videos);

        $this->createIssueWith($videos)->assertCreated();

        $attachments = ReportAttachment::orderBy('id')->get();
        $this->assertSame(['video/mp4', 'video/webm', 'video/quicktime'], $attachments->pluck('mime_type')->all());
        $this->assertSame(['Checkout bug.mp4', 'steps.webm', 'Screen Recording.mov'], $attachments->pluck('original_filename')->all());

        foreach ($attachments as $i => $attachment) {
            $this->assertStringEndsWith('.' . ['mp4', 'webm', 'mov'][$i], $attachment->storage_path);
            $this->assertSame($originals[$i], Storage::disk('public')->get($attachment->storage_path));
        }
    }

    public function test_a_video_can_be_added_to_an_existing_issue()
    {
        $issue = $this->makeIssue($this->project, $this->staff);

        $this->addTo($issue, [$this->video('after-fix.mp4')])
            ->assertOk()
            ->assertJsonPath('report.attachments.0.mime_type', 'video/mp4');
    }

    public function test_videos_may_be_up_to_25_mb_but_images_and_pdfs_stay_at_5_mb()
    {
        $this->createIssueWith([$this->video('long.mp4', 'mp4', 20 * 1024)])->assertCreated();

        $this->createIssueWith([$this->video('too-long.mp4', 'mp4', 25 * 1024 + 1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments.0');

        $this->createIssueWith([UploadedFile::fake()->image('big.jpg', 100, 100)->size(6000)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachments.0' => 'Images and PDFs must be 5 MB or smaller.']);

        $this->assertSame(1, Report::count());
    }

    public function test_a_file_named_like_a_video_that_is_not_one_is_rejected()
    {
        $this->createIssueWith([$this->realUpload('clip.mp4', '<html><script>alert(1)</script></html>')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments.0');

        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_a_video_is_stored_under_its_real_type_whatever_it_is_called()
    {
        // Saved as .html, a file like this would be served to the browser as a web page
        $this->createIssueWith([$this->video('recording.html')])->assertCreated();

        $this->assertStringEndsWith('.mp4', ReportAttachment::firstOrFail()->storage_path);
    }

    // ------------------------------------------------------------------ Existing behaviour

    public function test_attachments_added_to_an_existing_issue_are_processed_the_same_way()
    {
        $issue = $this->makeIssue($this->project, $this->staff);

        $this->addTo($issue, [UploadedFile::fake()->image('after-fix.png', 2600, 1300)])
            ->assertOk()
            ->assertJsonPath('message', 'Attachments uploaded successfully.')
            ->assertJsonPath('report.attachments.0.mime_type', 'image/webp')
            ->assertJsonPath('report.attachments.0.original_filename', 'after-fix.webp');

        $attachment = $issue->attachments()->firstOrFail();
        $this->assertSame([2000, 1000, IMAGETYPE_WEBP], $this->storedImage($attachment));
        $this->assertSame(1, $issue->activityLogs()->where('action', 'added_attachments')->count());
    }

    public function test_the_issue_gives_the_page_a_path_that_really_exists_for_each_attachment()
    {
        $this->createIssueWith([UploadedFile::fake()->image('shot.png', 800, 600)])->assertCreated();
        $issue = Report::firstOrFail();

        $path = $this->actingAs($this->staff)->getJson('/api/reports/' . $issue->uuid)
            ->assertOk()
            ->json('report.attachments.0.storage_path');

        // The page shows the image from /storage/{storage_path}
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('/storage/' . $path, ReportAttachment::firstOrFail()->getUrl());
    }

    public function test_deleting_an_issue_removes_its_stored_images()
    {
        $this->createIssueWith([UploadedFile::fake()->image('shot.png', 800, 600), $this->pdf()])->assertCreated();
        $issue = Report::firstOrFail();
        $this->assertCount(2, Storage::disk('public')->allFiles());

        $this->actingAs($this->manager)->deleteJson('/api/reports/' . $issue->uuid)->assertOk();

        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->assertSame(0, ReportAttachment::count());
    }

    public function test_only_people_who_can_edit_the_issue_can_add_attachments()
    {
        $issue = $this->makeIssue($this->project, $this->staff);
        [, $outsideManager] = $this->makeCompany('Borneo');

        $this->addTo($issue, [UploadedFile::fake()->image('x.png', 100, 100)], $outsideManager)->assertForbidden();

        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    // ------------------------------------------------------------------ Invalid files

    public function test_a_file_that_only_pretends_to_be_an_image_is_rejected()
    {
        $this->createIssueWith([UploadedFile::fake()->createWithContent('photo.png', "<?php echo 'not an image';")])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachments' => '"photo.png" is not a valid JPEG, PNG, GIF or WebP image.']);

        $this->assertSame(0, Report::count());
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_a_damaged_image_is_rejected_and_nothing_is_saved()
    {
        // A real PNG cut off part-way: the header is intact, the picture cannot be decoded
        $whole = UploadedFile::fake()->image('ok.png', 800, 600);
        $png = file_get_contents($whole->getRealPath());
        $damaged = UploadedFile::fake()->createWithContent('damaged.png', substr($png, 0, 60));

        $this->createIssueWith([UploadedFile::fake()->image('fine.png', 100, 100), $damaged])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachments' => '"damaged.png" could not be read as an image. It may be damaged.']);

        // The whole request is refused: no issue, and the good file is not left behind
        $this->assertSame(0, Report::count());
        $this->assertSame(0, ReportAttachment::count());
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_the_error_names_the_file_that_could_not_be_used()
    {
        config(['images.attachments.max_pixels' => 1_000_000]);

        $message = $this->createIssueWith([UploadedFile::fake()->image('huge-scan.jpg', 2000, 1000)])
            ->assertStatus(422)
            ->json('errors.attachments.0');

        $this->assertStringContainsString('"huge-scan.jpg" is too large to process (2000 x 1000 pixels)', $message);
        $this->assertSame(0, Report::count());
    }

    public function test_disallowed_types_and_oversized_files_are_rejected()
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $bmp = UploadedFile::fake()->image('old.bmp', 100, 100);
        $tooBig = UploadedFile::fake()->image('big.jpg', 100, 100)->size(5121);
        $document = UploadedFile::fake()->create('notes.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        foreach ([$svg, $bmp, $tooBig, $document] as $file) {
            $this->createIssueWith([$file])->assertStatus(422)->assertJsonValidationErrors('attachments.0');
        }

        // More than five files at once
        $this->createIssueWith(array_map(fn ($n) => UploadedFile::fake()->image("{$n}.png", 50, 50), range(1, 6)))
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments');

        $this->assertSame(0, Report::count());
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_a_bad_file_added_to_an_existing_issue_changes_nothing()
    {
        $issue = $this->makeIssue($this->project, $this->staff);

        $this->addTo($issue, [UploadedFile::fake()->createWithContent('photo.jpg', 'not an image at all')])->assertStatus(422);

        $this->assertSame(0, $issue->attachments()->count());
        $this->assertSame(0, $issue->activityLogs()->where('action', 'added_attachments')->count());
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_an_issue_can_still_be_created_without_attachments()
    {
        $this->createIssueWith([])->assertCreated();

        $this->assertSame(1, Report::count());
        $this->assertSame(0, ReportAttachment::count());
    }
}
