"""Real Craft CP upload regression checks. Use ONLY a disposable PNG/JPEG asset.

EDITOR_TEST_PASSWORD=... python3 tests/editor-http.py BASE_URL ASSET_ID [USERNAME]
The final check replaces the asset with the same image through the editor endpoint.
"""
import http.cookiejar
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

base = sys.argv[1].rstrip('/')
asset_id = int(sys.argv[2])
username = sys.argv[3] if len(sys.argv) > 3 else 'admin'
password = os.environ['EDITOR_TEST_PASSWORD']
client = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
)


def config():
    html = client.open(base + '/admin/login').read().decode()
    return json.loads(re.search(r'window.Craft = (\{.*?\});', html, re.S).group(1))


settings = config()


def action(route, values, image=None, csrf=True):
    data = dict(values)
    headers = {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Craft-Cp-Request': 'true',
    }
    if csrf:
        data[settings['csrfTokenName']] = settings['csrfTokenValue']
    if image is None:
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
        body = urllib.parse.urlencode(data).encode()
    else:
        boundary = 'editor-test-' + uuid.uuid4().hex
        headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
        parts = [
            f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
            for k, v in data.items()
        ]
        parts.extend([
            f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="edited.png"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode(),
            image,
            f'\r\n--{boundary}--\r\n'.encode(),
        ])
        body = b''.join(parts)
    request = urllib.request.Request(
        settings['actionUrl'].rstrip('/') + '/' + route, data=body, headers=headers
    )
    try:
        response = client.open(request)
    except urllib.error.HTTPError as error:
        response = error
    content = response.read()
    try:
        return response.status, json.loads(content)
    except ValueError:
        return response.status, {'success': False, 'message': 'Non-JSON response'}


status, result = action('users/login', {'loginName': username, 'password': password})
assert status == 200 and not result.get('errorCode'), 'Login failed'
settings = config()
route = 'craft-image-enhancer/article-image/'
status, info = action(route + 'asset-info', {'assetId': asset_id})
assert info.get('success'), (status, info)
response = client.open(info['editorSourceUrl'])
assert 'no-store' in response.headers['Cache-Control']
assert response.headers['X-Content-Type-Options'] == 'nosniff'
original = response.read()
version = info['editorVersion']
checks = [
    ('missing upload', {'assetId': asset_id, 'version': version}, None, True),
    ('stale version', {'assetId': asset_id, 'version': 'stale'}, original, True),
    ('unowned preview', {'assetId': asset_id, 'version': version, 'token': 'invalid', 'previewId': asset_id}, original, True),
    ('invalid bytes', {'assetId': asset_id, 'version': version}, b'not an image', True),
    ('missing CSRF', {'assetId': asset_id, 'version': version}, original, False),
]
for label, data, image, csrf in checks:
    status, result = action(route + 'save-editor', data, image, csrf)
    assert status >= 400 or result.get('success') is False, (label, status, result)
    print('PASS:', label, 'rejected')
assert client.open(info['editorSourceUrl']).read() == original, 'Rejected upload changed asset'
status, result = action(route + 'save-editor', {'assetId': asset_id, 'version': version}, original)
assert status == 200 and result.get('success'), (status, result)
_, updated = action(route + 'asset-info', {'assetId': asset_id})
assert updated['assetId'] == asset_id
assert (updated['width'], updated['height']) == (info['width'], info['height'])
print('PASS: real replacement preserves asset ID and dimensions')
print('6 HTTP regression checks passed')
