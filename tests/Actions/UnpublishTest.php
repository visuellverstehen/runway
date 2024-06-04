<?php

namespace StatamicRadPack\Runway\Tests\Actions;

use Illuminate\Support\Facades\Config;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use StatamicRadPack\Runway\Actions\Unpublish;
use StatamicRadPack\Runway\Runway;
use StatamicRadPack\Runway\Tests\Fixtures\Models\Post;
use StatamicRadPack\Runway\Tests\TestCase;

class UnpublishTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    /** @test */
    public function it_returns_title()
    {
        $this->assertEquals('Unpublish', Unpublish::title());
    }

    /** @test */
    public function is_visible_to_eloquent_model()
    {
        $visibleTo = (new Unpublish())->context([])->visibleTo(Post::factory()->create());

        $this->assertTrue($visibleTo);
    }

    /** @test */
    public function is_not_visible_to_unpublished_eloquent_model()
    {
        $visibleTo = (new Unpublish())->context([])->visibleTo(Post::factory()->unpublished()->create());

        $this->assertFalse($visibleTo);
    }

    /** @test */
    public function is_not_visible_to_eloquent_model_when_resource_is_read_only()
    {
        Config::set('runway.resources.StatamicRadPack\Runway\Tests\Fixtures\Models\Post.read_only', true);
        Runway::discoverResources();

        $visibleTo = (new Unpublish())->context([])->visibleTo(Post::factory()->create());

        $this->assertFalse($visibleTo);
    }

    /** @test */
    public function is_not_visible_to_entry()
    {
        Collection::make('posts')->save();

        $visibleTo = (new Unpublish())->context([])->visibleTo(
            tap(Entry::make()->collection('posts')->slug('hello-world'))->save()
        );

        $this->assertFalse($visibleTo);
    }

    /** @test */
    public function is_visible_to_eloquent_models_in_bulk()
    {
        $posts = Post::factory()->count(3)->create();

        $visibleToBulk = (new Unpublish())->context([])->visibleToBulk($posts);

        $this->assertTrue($visibleToBulk);
    }

    /** @test */
    public function is_not_visible_to_eloquent_models_in_bulk_when_not_all_models_are_published()
    {
        $posts = Post::factory()->count(3)->create();
        $posts->first()->update(['published' => false]);

        $visibleToBulk = (new Unpublish())->context([])->visibleToBulk($posts);

        $this->assertFalse($visibleToBulk);
    }

    /** @test */
    public function super_user_is_authorized()
    {
        $user = User::make()->makeSuper()->save();

        $authorize = (new Unpublish())->authorize($user, Post::factory()->create());

        $this->assertTrue($authorize);
    }

    /** @test */
    public function user_with_permission_is_authorized()
    {
        Role::make('editor')->addPermission('edit post')->save();

        $user = User::make()->assignRole('editor')->save();

        $authorize = (new Unpublish())->authorize($user, Post::factory()->create());

        $this->assertTrue($authorize);

        Role::find('editor')->delete();
    }

    /** @test */
    public function user_without_permission_is_not_authorized()
    {
        $user = User::make()->save();

        $authorize = (new Unpublish())->authorize($user, Post::factory()->create());

        $this->assertFalse($authorize);
    }

    /** @test */
    public function it_publishes_models()
    {
        $posts = Post::factory()->count(5)->create();

        $posts->each(fn (Post $post) => $this->assertTrue($post->published));

        (new Unpublish)->run($posts, []);

        $posts->each(fn (Post $post) => $this->assertFalse($post->published));
    }
}