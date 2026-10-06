# Coming from NoBlogs

If your blog lived on NoBlogs, this page is for you. NoBlogs4Ever exists so that your writing, your images and your readers' links can come back online — on a server run by someone you trust, or by you.

## 1. Find a home

NoBlogs4Ever is software, not a single website. You can:

* **join a community that runs it** — ask around in the networks you were part of, or look for operators who announce that they host former NoBlogs sites;
* **run it yourself**, alone or with your collective — see [Deploying to production](../operator-guide/deployment.md). It is designed for small, volunteer crews.

Pick operators you trust: whoever runs the server can technically read everything stored on it. The [privacy page](../architecture/privacy.md) explains exactly what the software keeps, so you can compare it with what operators promise.

## 2. Check what your backup contains

NoBlogs was built on WordPress, so most backups look like one of these:

| What you have | What it gives you |
|---|---|
| An **`.xml` file** from *Tools → Export* (WordPress "WXR" export) | All posts, pages, comments, categories, tags, menus, authors and the *list* of your images. Not the image files themselves. |
| A **folder or ZIP of uploads** (often named `files`, `uploads` or `blogs.dir/<number>/files`) | The actual images and documents. |
| Both of the above | Everything needed for a full restore. This is the best case. |
| A **mirror of the public pages** (made with wget, HTTrack, SingleFile, WebRecorder, the Wayback Machine…) | A read-only copy. It can't be imported automatically yet — see [below](#if-you-only-have-a-website-mirror). |

Keep your original backup files safe and unchanged. The import works on a copy.

## 3. Put it together

Make a ZIP that contains your `.xml` export and, if you have it, your uploads folder. The folder names don't need to be exact: the importer finds images by their old path (`2019/05/photo.jpg`), or by a unique file name.

```
my-blog.zip
├── myblog.WordPress.2026-09-06.xml
└── files/
    ├── 2012/03/demo-poster.jpg
    └── 2019/05/photo.jpg
```

No uploads folder? Upload the `.xml` alone. Your posts come back; the report lists every image that is missing so you can add them later.

## 4. Import

On your new site open **Tools → Import publication**, upload the file, and follow the steps in [Importing an existing site](importing.md): the platform takes an inventory, asks who wrote what, then rebuilds your blog in the background and emails you when it is done.

Things the importer takes care of for NoBlogs-era blogs:

* old Multisite media links such as `https://yourblog.noblogs.org/files/2012/03/poster.jpg` and `/files/…` are pointed at the restored files;
* thumbnails and resized images (`poster-300x200.jpg`) are matched to their new versions;
* links between your own posts and pages are rewritten to the new address;
* publication dates, drafts, scheduled and password-protected posts, comments (with their threads) and categories stay as they were;
* nothing is ever downloaded from the old address — it isn't coming back, and the report tells you exactly which links still point there.

## 5. Check and share

Read the import report, open a few posts with images, check your menus and feeds. Missing images? Add them to a ZIP and import again; only the missing pieces are added, nothing is duplicated.

Then tell your readers where you are now. Old links pointing to your previous address can't be redirected, because nobody controls that address any more. Post your new address wherever your community will see it.

## Your new address

Sites live at `https://<name>.<the operator's domain>`. You can usually keep your old name — `yourblog.noblogs.org` can become `yourblog.example.org` — unless it is reserved or already taken.

## If you only have a website mirror

Mirrors made with wget, SingleFile or similar tools contain the rendered pages, not the WordPress data, so they can't be imported automatically yet. You can still:

* recreate the important posts by hand (copy the text, upload the images), or
* ask the operator to publish the mirror as a static archive alongside your new site.

A converter from static mirrors to WordPress content is on the [roadmap](../getting-started/project-status.md#roadmap--good-places-to-help). If you have such a mirror and can share an anonymised sample, it would help a lot.

## If something went wrong

Every import keeps your original upload and can be run again safely. If the report says something you don't understand, ask your operator — and if it looks like a bug, they can report it to the project with the (anonymised) report attached.
