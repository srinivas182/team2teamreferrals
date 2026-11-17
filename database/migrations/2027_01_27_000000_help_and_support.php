<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sprint 48a: the help centre (articles by topic, search, "was this helpful?") and support tickets (a staff inbox
 * with assignment, priority, internal notes, saved replies and response-time targets; replies by web or email).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('category_id')->constrained('help_categories');
            $table->string('slug', 120)->unique();
            $table->string('title', 160);
            $table->string('summary', 400); // the short answer, shown first and used for search snippets and structured data
            $table->text('body_md');
            $table->text('body_html');
            $table->boolean('howto')->default(false); // numbered steps: marked up as HowTo
            $table->string('audience', 12)->default('public'); // public (indexed), members, owners
            $table->string('status', 12)->default('draft'); // draft, published
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->date('review_due_on')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('helpful_yes')->default(0);
            $table->unsignedInteger('helpful_no')->default(0);
            $table->timestamps();
            $table->index(['status', 'audience']);
        });

        Schema::create('help_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('help_articles')->cascadeOnDelete();
            $table->boolean('helpful');
            $table->string('comment', 1000)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // What people searched for, and how many articles matched: searches with no match show what to write next.
        Schema::create('help_searches', function (Blueprint $table) {
            $table->id();
            $table->string('query', 200);
            $table->unsignedSmallInteger('results');
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('number', 16)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('email', 190)->index();
            $table->string('subject', 160);
            $table->string('category', 16)->default('other');
            $table->string('priority', 8)->default('normal'); // low, normal, high, urgent
            $table->string('status', 10)->default('open'); // open, pending (waiting on the customer), solved, closed
            $table->string('source', 10)->default('web'); // web, email, ally
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('access_hash', 64)->nullable(); // for people without an account: the link they were emailed
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('breach_alerted_at')->nullable();
            $table->timestamp('solved_at')->nullable();
            $table->timestamp('last_customer_at')->nullable();
            $table->timestamp('last_staff_at')->nullable();
            $table->string('rating', 4)->nullable(); // good, bad
            $table->string('rating_comment', 1000)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'assignee_id']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('author', 8); // customer, staff, system
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('internal')->default(false); // staff-only notes
            $table->string('via', 8)->default('web'); // web, email
            $table->json('attachments')->nullable(); // [{path, name, size, type}]
            $table->string('email_id', 190)->nullable()->unique(); // inbound message id: an email is never added twice
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('support_macros', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        $cats = [
            ['getting-started', 'Getting started', 'Joining a network, claiming a seat and setting up your team.'],
            ['referrals', 'Sending and receiving referrals', 'How referrals move between teams, deadlines and referral fees.'],
            ['billing', 'Billing and invoices', 'Seat prices, free trials, cards, invoices and refunds.'],
            ['partners', 'Partner directory', 'Advertising your business to home buyers and sellers.'],
            ['account', 'Your account and security', 'Signing in, two-step sign-in, notifications and privacy.'],
        ];
        foreach ($cats as $i => [$slug, $name, $desc]) {
            DB::table('help_categories')->insert(['slug' => $slug, 'name' => $name, 'description' => $desc, 'sort' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
        $cat = fn (string $slug) => DB::table('help_categories')->where('slug', $slug)->value('id');
        $articles = [
            ['getting-started', 'how-to-claim-a-seat', 'How do I claim a seat in my city?', 'Open the network for your industry, find your city, and choose "Claim this seat". You confirm your license, apply, and complete the network training; once approved, the seat is yours and referrals for that area come to you.', true,
                "1. Go to **Groups** and open the network for your industry.\n2. Find your city on the map or in the list. If the seat is open, choose **Claim this seat**.\n3. Create your account (or sign in) and add your license where your industry needs one. We check it with the state.\n4. Send your application. The network owner reviews it.\n5. Complete the short network training.\n6. Once approved, your free trial starts and referrals for your area come to you.\n\nIf the seat is already taken, you can join its waitlist. You'll be told as soon as it opens."],
            ['referrals', 'how-referral-deadlines-work', 'How long do I have to accept a referral?', 'Each network sets a response deadline. If the receiving team doesn’t accept in time, the referral can go to the backup team for that area, so clients are never left waiting.', false,
                "When a referral is sent to your seat you get an email, a notification and (if you turned them on) a push or text alert.\n\nOpen it from your **Inbox** to see what the client needs, then accept or decline. Each network sets how long you have; the countdown is shown on the referral.\n\nIf the deadline passes, the referral may move to the backup team for your area. Accepting quickly also helps your rating in the network."],
            ['referrals', 'how-referral-fees-work', 'How do referral fees work?', 'The fee is agreed between the two teams when a referral is sent, and is paid team to team when the deal closes. The platform never holds that money.', false,
                "The referral fee is agreed between the sending and receiving teams when the referral is sent, following your network's rules.\n\nWhen the deal closes, the receiving team records the outcome and pays the fee directly to the sending team. You can track what's owed and paid under **Earnings**.\n\nSome industries have rules about referral fees (for example, real estate licensing). Your industry's page explains what applies."],
            ['billing', 'seat-billing-and-free-trial', 'How does billing work for my seat?', 'Each seat has a free trial, then a monthly price set by its network, billed in arrears on the 1st. Where the network offers it, a month in which no referral was offered to your seat is free.', false,
                "Your seat starts with a free trial. We remind you 14, 7 and 1 days before it ends.\n\nAfter the trial, you're billed on the 1st of each month for the month just ended, at your network's seat price. You can also choose a yearly plan where your network offers one.\n\nAdd or replace your card under **Billing**. If a payment fails we try again on days 3 and 5 and let you know each time.\n\nInvoices are kept under **Billing** with a PDF for each."],
            ['partners', 'advertise-in-the-partner-directory', 'How do I advertise my business in the partner directory?', 'Apply on the Advertise page with your details, license where your field needs one, and the cities you serve. We review every application; after a free trial you pay a flat monthly price per city, never per lead.', true,
                "1. Open **Advertise with us** and choose your kind of business.\n2. Add your details, a short description, your logo and the cities you serve. Mortgage lenders and other licensed fields add their license number.\n3. Send the application. We check it, usually within two business days.\n4. Once approved, your listing goes live and your free trial starts.\n5. Sign in to your partner account with the link we email you to add a card, change cities, see enquiries and download stats.\n\nEach city shows a limited number of partners per category. If a city is full, you join its waitlist and pay nothing for it until a place opens."],
            ['account', 'turn-on-two-step-sign-in', 'How do I turn on two-step sign-in?', 'Go to Settings, then Security, and choose "Turn on two-step sign-in". Scan the code with an authenticator app, enter the 6-digit code, and save your recovery codes.', true,
                "1. Open **Settings → Security**.\n2. Choose **Turn on two-step sign-in**.\n3. Scan the QR code with an authenticator app (for example Google Authenticator or 1Password).\n4. Enter the 6-digit code from the app to finish.\n5. Save your recovery codes somewhere safe. Each one works once if you lose your phone."],
        ];
        foreach ($articles as [$c, $slug, $title, $summary, $howto, $md]) {
            DB::table('help_articles')->insert(['ulid' => strtolower((string) Str::ulid()), 'category_id' => $cat($c), 'slug' => $slug, 'title' => $title, 'summary' => $summary, 'body_md' => $md,
                'body_html' => (string) Str::markdown($md, ['html_input' => 'strip', 'allow_unsafe_links' => false]), 'howto' => $howto, 'audience' => 'public', 'status' => 'published',
                'published_at' => $now, 'review_due_on' => $now->copy()->addMonths(6)->toDateString(), 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('support_macros')->insert([
            ['title' => 'Thanks, looking into it', 'body' => "Hi {first_name},\n\nThanks for getting in touch. I'm looking into this now and will update you shortly.", 'created_at' => $now, 'updated_at' => $now],
            ['title' => 'Resolved, anything else?', 'body' => "Hi {first_name},\n\nThis should now be sorted. If anything still isn't right, just reply to this message and it reopens.", 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('support_macros');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('help_searches');
        Schema::dropIfExists('help_feedback');
        Schema::dropIfExists('help_articles');
        Schema::dropIfExists('help_categories');
    }
};
