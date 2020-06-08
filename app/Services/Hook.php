<?php

declare(strict_types=1);

namespace App\Services;

use App\Events;
use App\Notifications;
use Closure;
use Event;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Notification;

class Hook
{
    /**
     * Add an item to menu.
     *
     * @param string $category 'user' or 'admin' or 'explore'
     * @param int    $position where to insert the given item, start from 0
     * @param array  $menu     e.g.
     *                         [
     *                         'title' => 'Title',       # will be translated by translator
     *                         'link'  => 'user/config', # route link
     *                         'icon'  => 'fa-book',     # font-awesome icon
     *                         'new-tab' => false,        # open the link in new tab or not, false by default
     *                         ]
     */
    public static function addMenuItem(string $category, int $position, array $menu): void
    {
        $class = 'App\Events\Configure'.Str::title($category).'Menu';

        Event::listen($class, function ($event) use ($menu, $position, $category) {
            $new = [];

            $offset = 0;
            foreach ($event->menu[$category] as $item) {
                // Push new menu items at the given position
                if ($offset == $position) {
                    $new[] = $menu;
                }

                $new[] = $item;
                $offset++;
            }

            if ($position >= $offset) {
                $new[] = $menu;
            }

            $event->menu[$category] = $new;
        });
    }

    public static function addRoute(Closure $callback): void
    {
        Event::listen(Events\ConfigureRoutes::class, function ($event) use ($callback) {
            return call_user_func($callback, $event->router);
        });
    }

    public static function addStyleFileToPage($urls, $pages = ['*']): void
    {
        Event::listen(Events\RenderingHeader::class, function ($event) use ($urls, $pages) {
            foreach ($pages as $pattern) {
                if (!request()->is($pattern)) {
                    continue;
                }

                foreach ((array) $urls as $url) {
                    $event->addContent("<link rel=\"stylesheet\" href=\"$url\">");
                }

                return;
            }
        });
    }

    public static function addScriptFileToPage($urls, $pages = ['*']): void
    {
        Event::listen(Events\RenderingFooter::class, function ($event) use ($urls, $pages) {
            foreach ($pages as $pattern) {
                if (!request()->is($pattern)) {
                    continue;
                }

                foreach ((array) $urls as $url) {
                    $event->addContent("<script src=\"$url\"></script>");
                }

                return;
            }
        });
    }

    public static function addUserBadge(string $text, $color = 'primary'): void
    {
        Event::listen(
            Events\RenderingBadges::class,
            function (Events\RenderingBadges $event) use ($text, $color) {
                $event->badges[] = compact('text', 'color');
            }
        );
    }

    public static function sendNotification($users, string $title, $content = ''): void
    {
        Notification::send(Arr::wrap($users), new Notifications\SiteMessage($title, $content));
    }

    public static function pushMiddleware($middleware)
    {
        app()->make('Illuminate\Contracts\Http\Kernel')->pushMiddleware($middleware);
    }
}
