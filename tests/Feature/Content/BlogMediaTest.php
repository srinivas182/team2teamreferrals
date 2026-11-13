<?php

use App\Domains\Content\Posts;
use App\Domains\Identity\Authorization\Permissions;
use App\Domains\Identity\Models\User;
use App\Support\Scanning\VirusScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.default' => 'public', 'seo.indexable' => true]);
    Storage::fake('public');
    $this->verdict = 'clean';
    app()->instance(VirusScanner::class, new class($this) implements VirusScanner
    {
        public function __construct(private readonly object $t) {}

        public function scan(string $absolutePath): string
        {
            return $this->t->verdict;
        }
    });
    $this->editor = User::factory()->create(['first_name' => 'Dana', 'last_name' => 'Reyes', 'two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->editor->assignRole(Permissions::STAFF);
    $this->editor->givePermissionTo('content.manage');
    $this->summary = 'An out-of-area referral is a client you pass to a trusted pro in another city; you agree the fee, the client consents, and the platform tracks it.';
    $this->body = '<h2>How it works</h2><p>'.str_repeat('Referrals between verified teams are tracked step by step. ', 4).'</p><script>alert(1)</script>';
    $this->jpeg = function (int $w, int $h): string {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 30, 120, 200));
        ob_start();
        imagejpeg($img, null, 85);
        $jpg = (string) ob_get_clean();
        // Add an EXIF segment with a GPS marker after the start of the image.
        $exif = "Exif\x00\x00GPSLatitude=37.1234;GPSLongitude=-113.5";

        return substr($jpg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpg, 2);
    };
});

it('[5] turns uploads into WebP without location data, requires alt text, and refuses non-images and infected files', function () {
    $this->actingAs($this->editor);
    $file = fn () => UploadedFile::fake()->createWithContent('team.jpg', ($this->jpeg)(2000, 1000));
    $this->post('/admin/content/media', ['file' => $file(), 'alt' => ''])->assertSessionHasErrors('alt');
    $this->post('/admin/content/media', ['file' => $file(), 'alt' => 'Two agents outside a house'])->assertSessionHas('status', 'media-uploaded');
    $m = DB::table('media')->first();
    $main = Storage::disk('public')->get($m->path);
    expect(substr((string) $main, 0, 4).substr((string) $main, 8, 4))->toBe('RIFFWEBP')->and((int) $m->width)->toBe(1600)->and((int) $m->height)->toBe(800)
        ->and($main)->not->toContain('GPSLatitude')->and(Storage::disk('public')->exists($m->thumb_path))->toBeTrue()
        ->and(getimagesizefromstring((string) Storage::disk('public')->get($m->thumb_path))[0])->toBe(480);
    $this->post('/admin/content/media', ['file' => UploadedFile::fake()->createWithContent('x.jpg', 'not an image at all'), 'alt' => 'Something here'])->assertSessionHasErrors('file');
    $this->verdict = 'infected';
    $this->post('/admin/content/media', ['file' => $file(), 'alt' => 'Two agents outside a house'])->assertSessionHasErrors('file');
    expect(DB::table('media')->count())->toBe(1);
    $staff = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $staff->assignRole(Permissions::STAFF);
    $this->actingAs($staff)->get('/admin/content/media')->assertForbidden();
});

it('[3] publishes posts that lead with the answer and carry Article data with author, reviewer and dates', function () {
    $this->actingAs($this->editor)->post('/admin/content/categories', ['name' => 'Referral basics']);
    $cat = DB::table('blog_categories')->value('id');
    $this->post('/admin/content/media', ['file' => UploadedFile::fake()->createWithContent('c.jpg', ($this->jpeg)(1200, 600)), 'alt' => 'A referral handshake']);
    $post = ['title' => 'What is an out-of-area referral?', 'slug' => 'out-of-area-referrals', 'answer_summary' => 'Too short', 'body' => 'Short', 'category_id' => $cat,
        'reviewer' => 'Jordan Lee, licensed broker', 'cover_media_id' => DB::table('media')->value('id'), 'seo' => []];
    $this->post('/admin/content/posts', $post)->assertSessionHas('status', 'post-saved');
    $ulid = DB::table('posts')->value('ulid');
    $this->post("/admin/content/posts/{$ulid}/publish")->assertSessionHasErrors('answer_summary');
    $this->post("/admin/content/posts/{$ulid}", [...$post, 'answer_summary' => $this->summary]);
    $this->post("/admin/content/posts/{$ulid}/publish")->assertSessionHasErrors('body');
    $this->post("/admin/content/posts/{$ulid}", [...$post, 'answer_summary' => $this->summary, 'body' => $this->body]);
    $this->get('/blog/out-of-area-referrals')->assertNotFound(); // still a draft
    $this->post("/admin/content/posts/{$ulid}/publish")->assertSessionHas('status', 'post-publish');
    expect(DB::table('posts')->value('body'))->not->toContain('script');
    $this->post('/logout');
    $this->get('/blog/out-of-area-referrals')->assertOk()->assertInertia(function ($p) {
        $props = $p->toArray()['props'];
        expect($props['post'])->toMatchArray(['summary' => $this->summary, 'author' => 'Dana Reyes', 'reviewer' => 'Jordan Lee, licensed broker', 'category' => 'Referral basics', 'alt' => 'A referral handshake']);
        $article = collect($props['seo']['schema'])->firstWhere('@type', 'Article');
        expect($article['author']['name'])->toBe('Dana Reyes')->and($article['reviewedBy']['name'])->toBe('Jordan Lee, licensed broker')
            ->and($article['description'])->toBe($this->summary)->and($article['image'])->toEndWith('.webp')->and($article['datePublished'])->not->toBeEmpty()
            ->and(array_column(collect($props['seo']['schema'])->firstWhere('@type', 'BreadcrumbList')['itemListElement'], 'name'))->toBe(['Home', 'Blog', 'What is an out-of-area referral?'])
            ->and($props['seo']['robots'])->toContain('index, follow')->and(mb_strlen($props['seo']['description']))->toBeGreaterThanOrEqual(50);

        return $p;
    });
    $this->get('/blog')->assertInertia(fn ($p) => $p->component('Blog')->where('posts.0.summary', $this->summary)->where('posts.0.image', fn ($i) => str_ends_with((string) $i, '-480.webp')));
    $this->get('/blog/category/referral-basics')->assertInertia(fn ($p) => $p->where('category', 'Referral basics')->has('posts', 1));
    $this->get('/blog/category/nothing')->assertNotFound();
});

it('[3] publishes scheduled posts when their time comes', function () {
    $this->actingAs($this->editor)->post('/admin/content/posts', ['title' => 'Later', 'slug' => 'later', 'answer_summary' => $this->summary, 'body' => $this->body, 'seo' => []]);
    $ulid = DB::table('posts')->value('ulid');
    $this->post("/admin/content/posts/{$ulid}/publish", ['at' => now()->addHour()->format('Y-m-d H:i')])->assertSessionHas('status', 'post-publish-scheduled');
    $this->get('/blog/later')->assertNotFound();
    $this->travel(61)->minutes();
    expect(Posts::publishDue())->toBe(1);
    $this->get('/blog/later')->assertOk();
});

it('[4] manages header and footer menus shown on public pages', function () {
    $this->actingAs($this->editor)->post('/admin/content/menus', ['location' => 'header', 'items' => [['label' => 'Blog', 'url' => '/blog'], ['label' => 'Bad', 'url' => 'javascript:alert(1)']]])->assertSessionHasErrors('items');
    $this->post('/admin/content/menus', ['location' => 'header', 'items' => [['label' => 'Blog', 'url' => '/blog'], ['label' => 'Partner site', 'url' => 'https://example.org']]])->assertSessionHas('status', 'menu-saved');
    $this->get('/blog')->assertInertia(fn ($p) => $p->where('menus.header', [['Blog', '/blog'], ['Partner site', 'https://example.org']]));
});

it('[6] lists published pages and posts, with their answers, in the sitemap and llms.txt (never drafts or hidden ones)', function () {
    $this->actingAs($this->editor);
    $page = ['title' => 'How it works', 'slug' => 'how-it-works', 'template' => 'page', 'answer_summary' => $this->summary, 'seo' => [], 'blocks' => [['type' => 'cta', 'heading' => 'Join', 'label' => 'Go', 'url' => '/register']]];
    $this->post('/admin/content', $page);
    $this->post('/admin/content/'.DB::table('cms_pages')->value('ulid').'/publish');
    $this->post('/admin/content', [...$page, 'slug' => 'hidden-page', 'title' => 'Hidden', 'seo' => ['noindex' => true]]);
    $this->post('/admin/content/'.DB::table('cms_pages')->latest('id')->value('ulid').'/publish');
    $this->post('/admin/content', [...$page, 'slug' => 'draft-page', 'title' => 'Draft']);
    $this->post('/admin/content/posts', ['title' => 'A post', 'slug' => 'a-post', 'answer_summary' => $this->summary, 'body' => $this->body, 'seo' => []]);
    $this->post('/admin/content/posts/'.DB::table('posts')->value('ulid').'/publish');
    $llms = $this->get('/llms.txt')->assertOk()->getContent();
    expect($llms)->toContain('## Pages and articles')->toContain('[How it works](')->toContain('/how-it-works): '.$this->summary)->toContain('[A post](')
        ->not->toContain('Hidden')->not->toContain('draft-page');
    $xml = $this->get('/sitemaps/content.xml')->assertOk()->getContent();
    expect($xml)->toContain('/how-it-works')->toContain('/blog/a-post')->toContain('/blog<')->not->toContain('hidden-page')->not->toContain('draft-page');
});
