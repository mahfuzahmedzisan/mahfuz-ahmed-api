# Avatar image processing

Profile photos are cropped in the browser, then the API encodes a 512×512 WebP and runs [spatie/image-optimizer](https://github.com/spatie/image-optimizer).

`spatie/image` converts the upload to WebP. `spatie/laravel-image-optimizer` only recompresses that WebP (via `cwebp`). It does not change the format.

## PHP

GD must be able to write WebP:

```bash
php -r "var_dump(function_exists('imagewebp'));"
```

## Optimizer binary

Install `cwebp` and keep it on `PATH`. If it is missing, the optimizer skips WebP and the encoded file is still stored.

### Windows

1. Download libwebp from https://developers.google.com/speed/webp/download
2. Add the folder that contains `cwebp.exe` to `PATH`
3. Confirm with `cwebp -version`

### Linux / Docker

```bash
sudo apt-get install -y webp
```

In a PHP image, also build GD with WebP (`libwebp-dev`) so `imagewebp` exists. This API does not ship a Dockerfile yet; add the `webp` package to the runtime image when you containerize it.

Optional tools (jpegoptim, optipng, pngquant) are unused for avatars because every stored file is already WebP.

## Config

Published at `config/image-optimizer.php`. Avatar encode quality is 82 in `App\Services\AvatarStorageService`; `cwebp` is set to `-q 85`.
