<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @foreach($pages as $page)
        <url>
            <loc>{{ $page->slug === 'home' ? route('home') : route('page.show', $page->slug) }}</loc>
            <lastmod>{{ $page->updated_at->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>{{ $page->slug === 'home' ? '1.0' : '0.7' }}</priority>
        </url>
    @endforeach
    @foreach(($posts ?? []) as $post)
        <url>
            <loc>{{ route('blog.show', $post->slug) }}</loc>
            <lastmod>{{ $post->updated_at->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.6</priority>
        </url>
    @endforeach
    @foreach(($staticRoutes ?? []) as $routeName)
        <url>
            <loc>{{ route($routeName) }}</loc>
            <changefreq>monthly</changefreq>
            <priority>0.3</priority>
        </url>
    @endforeach
    @foreach(($instructors ?? []) as $instructorEntry)
        <url>
            <loc>{{ route('instructors.show', $instructorEntry['slug']) }}</loc>
            <lastmod>{{ $instructorEntry['updated_at']->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.8</priority>
        </url>
    @endforeach
</urlset>
