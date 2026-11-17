<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 47a: the partner directory. Local businesses that serve home buyers and sellers (mortgage, title,
 * inspection, insurance…) advertise on city pages for a flat fee. It is advertising only: partners are never part of
 * the referral network, never pay per lead, and every listed partner in a city gets the same share of the top spot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 80);
            $table->string('singular', 80);
            // Licensed categories need a license number before approval, and staff confirm it with the regulator.
            $table->boolean('licensed')->default(false);
            $table->string('license_label', 60)->nullable();
            $table->string('license_lookup_url', 300)->nullable(); // "{number}" is replaced
            $table->string('schema_type', 60)->default('ProfessionalService');
            $table->json('faq')->nullable(); // [{q, a}]
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('slug', 120)->unique();
            $table->foreignId('category_id')->constrained('partner_categories');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // the partner portal (Sprint 47b)
            $table->string('business_name', 120);
            $table->string('contact_name', 120);
            $table->string('email', 190);
            $table->string('phone', 32)->nullable();
            $table->string('website', 300)->nullable();
            $table->text('description');
            $table->string('logo_path', 300)->nullable();
            $table->string('license_number', 60)->nullable();
            $table->string('license_state', 2)->nullable();
            $table->timestamp('license_verified_at')->nullable();
            $table->string('offer_title', 120)->nullable();
            $table->string('offer_details', 500)->nullable();
            $table->date('offer_expires_on')->nullable();
            $table->string('status', 16)->default('pending')->index(); // pending, approved, rejected, suspended
            $table->string('status_reason', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['category_id', 'status']);
        });

        Schema::create('partner_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('partner_categories');
            $table->foreignId('city_id')->constrained();
            $table->string('status', 16)->default('active'); // active, paused (billing and the waitlist arrive in 47b)
            $table->timestamps();
            $table->unique(['partner_id', 'city_id']);
            $table->index(['city_id', 'category_id', 'status']);
        });

        Schema::create('partner_enquiries', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 32)->nullable();
            $table->text('message');
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['partner_id', 'created_at']);
        });

        // Members vouching for a partner they work with. Shown on the listing; never changes the order.
        Schema::create('partner_endorsements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('note', 280)->nullable();
            $table->timestamps();
            $table->unique(['partner_id', 'team_id']);
        });

        $now = now();
        $faq = fn (string $pro, string $pros, string $extra) => json_encode([
            ['q' => "How do I choose a good {$pro}?", 'a' => $extra],
            ['q' => 'Are these businesses part of the referral network?', 'a' => "No. They are local {$pros} who pay a flat monthly fee to advertise here. Network members are not paid for sending clients to them, and they don’t pay for referrals."],
            ['q' => 'Who sees my message?', 'a' => "Only the {$pro} you write to. Your details are never sold or shared with other businesses."],
        ]);
        DB::table('partner_categories')->insert([
            ['slug' => 'mortgage-lenders', 'name' => 'Mortgage lenders', 'singular' => 'mortgage lender', 'licensed' => true, 'license_label' => 'NMLS ID',
                'license_lookup_url' => 'https://www.nmlsconsumeraccess.org/EntityDetails.aspx/COMPANY/{number}', 'schema_type' => 'FinancialService', 'sort' => 1,
                'faq' => $faq('mortgage lender', 'mortgage lenders', 'Check the lender’s NMLS ID on NMLS Consumer Access, compare the Loan Estimates from at least two or three lenders, and ask how fast they close in your area. You are free to choose any lender.')],
            ['slug' => 'title-escrow', 'name' => 'Title and escrow', 'singular' => 'title and escrow company', 'licensed' => false, 'license_label' => 'License number',
                'license_lookup_url' => null, 'schema_type' => 'ProfessionalService', 'sort' => 2,
                'faq' => $faq('title company', 'title and escrow companies', 'Ask what their fees cover, how they protect against wire fraud, and how quickly they can clear title. You have the right to choose your own title company in most states.')],
            ['slug' => 'home-inspectors', 'name' => 'Home inspectors', 'singular' => 'home inspector', 'licensed' => false, 'license_label' => 'License number',
                'license_lookup_url' => null, 'schema_type' => 'HomeAndConstructionBusiness', 'sort' => 3,
                'faq' => $faq('home inspector', 'home inspectors', 'Ask for a sample report, check their state license or certification, and confirm what is and isn’t included (roof, sewer line, radon).')],
            ['slug' => 'insurance-agents', 'name' => 'Insurance agents', 'singular' => 'insurance agent', 'licensed' => true, 'license_label' => 'State license or NPN',
                'license_lookup_url' => null, 'schema_type' => 'InsuranceAgency', 'sort' => 4,
                'faq' => $faq('insurance agent', 'insurance agents', 'Compare quotes for the same coverage, ask whether the agent represents one insurer or several, and check their state license.')],
            ['slug' => 'movers', 'name' => 'Movers', 'singular' => 'moving company', 'licensed' => false, 'license_label' => 'USDOT number',
                'license_lookup_url' => 'https://safer.fmcsa.dot.gov/query.asp?searchtype=ANY&query_type=queryCarrierSnapshot&query_param=USDOT&query_string={number}', 'schema_type' => 'MovingCompany', 'sort' => 5,
                'faq' => $faq('mover', 'movers', 'Get written estimates after a walk-through, check the USDOT number for interstate moves, and ask about valuation coverage.')],
            ['slug' => 'real-estate-attorneys', 'name' => 'Real estate attorneys', 'singular' => 'real estate attorney', 'licensed' => true, 'license_label' => 'Bar number',
                'license_lookup_url' => null, 'schema_type' => 'LegalService', 'sort' => 6,
                'faq' => $faq('attorney', 'real estate attorneys', 'Ask whether they charge a flat fee for closings, check their bar admission in your state, and confirm who will handle your file.')],
            ['slug' => 'home-stagers', 'name' => 'Home stagers', 'singular' => 'home stager', 'licensed' => false, 'license_label' => null,
                'license_lookup_url' => null, 'schema_type' => 'ProfessionalService', 'sort' => 7,
                'faq' => $faq('home stager', 'home stagers', 'Look at before and after photos, ask how long furniture rental lasts, and get the price in writing.')],
            ['slug' => 'real-estate-photographers', 'name' => 'Real estate photographers', 'singular' => 'real estate photographer', 'licensed' => false, 'license_label' => null,
                'license_lookup_url' => null, 'schema_type' => 'ProfessionalService', 'sort' => 8,
                'faq' => $faq('photographer', 'real estate photographers', 'Look at recent listings they shot, ask about turnaround time, drone licensing (FAA Part 107) and floor plans.')],
        ]);
        DB::table('partner_categories')->update(['created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_endorsements');
        Schema::dropIfExists('partner_enquiries');
        Schema::dropIfExists('partner_listings');
        Schema::dropIfExists('partners');
        Schema::dropIfExists('partner_categories');
    }
};
