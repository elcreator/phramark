<?php

namespace Phramark\Benchmark\Updates;

use Illuminate\Database\Schema\Blueprint;
use Winter\Storm\Database\Updates\Migration;
use Winter\Storm\Support\Facades\Schema;

/**
 * phramark_article mirrors benchmark/fixtures/cms/typo3-seed.sql so the
 * shared seeder (seed-articles.php) fills it; phramark_benchmark_pages holds
 * the admin workload's pages.
 */
class CreateTables extends Migration
{
    public function up()
    {
        Schema::create('phramark_article', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('category');
            $table->string('title');
            $table->text('introtext');
            $table->string('alias', 64);
            $table->string('hero_image');
            $table->string('author', 64)->nullable();
            $table->unsignedInteger('reading_time')->nullable();
            $table->unsignedInteger('published_at');
            $table->index(['category', 'published_at'], 'category_published');
        });

        Schema::create('phramark_benchmark_pages', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->text('content')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('phramark_benchmark_pages');
        Schema::dropIfExists('phramark_article');
    }
}
