"""Patches FileBird's compiled sidebar bundle for the Media Manager module.

Usage: python3 tools/media-manager-patch-bundle.py <filebird-pro/assets/dist> modules/media-manager/assets/dist

Written against FileBird Pro 6.5.9. The anchors use the bundle's minified names
(kp, di, nf, wd, $e, N...), which change on every upstream build: each must match
exactly once, so the run stops on the first one that moved. Find the new name by
running prettier on the upstream main.tsx-*.js and searching for the same code.
"""
import sys, os, hashlib, json, glob, shutil
src, dst = sys.argv[1], sys.argv[2]
main_src = glob.glob(os.path.join(src, 'main.tsx-*.js'))[0]
index_src = glob.glob(os.path.join(src, 'index-*.js'))[0]
s = open(main_src).read()
TOOLS = ('{type:"separator"},{key:"bizen_tools",type:"sub",label:N("tools"),children:['
         '{key:"bizen_attachment_size",type:"item",label:N("attachment_size"),className:"fb-p-[0_10px]"},'
         '...(window.fbv_data.wpml_folder_sync?[{key:"bizen_wpml_sync",type:"item",label:N("wpml_folder_sync"),className:"fb-p-[0_10px]"}]:[]),'
         '{key:"bizen_clear_all",type:"item",label:N("clear_all_data"),className:"fb-p-[0_10px]"}]}')
patches = [
    ('children:"FileBird"', 'children:kp.media_manager'),
    ('!!di&&O(Re,{children:[O("div",{className:`fb-flex', '!1&&O(Re,{children:[O("div",{className:`fb-flex'),
    ('children:a(nf,{})})}],{tree_mode:wd}=window.fbv_data',
     'children:a(nf,{})})},...(window.fbv_data.can_manage_options&&window.fbv_data.tree_mode==="attachment"?[' + TOOLS + ']:[])],{tree_mode:wd}=window.fbv_data'),
    ('E.key==="display_folder_id"&&u()',
     'E.key==="display_folder_id"&&u(),String(E.key).indexOf("bizen_")===0&&window.bizenMediaManager&&window.bizenMediaManager.tool(E.key,$e)'),
    ('if(jQuery("#fbv-feedback").length)', 'if(!1)'),
    ('window.open("https://ninjateam.org/support/","_blank")', 'void 0'),
    ('atob("aHR0cHM6Ly9wcmV2aWV3Lm5pbmphdGVhbS5vcmcvZmlsZWJpcmQvd3AtanNvbi9maWxlYmlyZC92NC9hZGRGZWVkYmFjaw==")', '""'),
]
for old, new in patches:
    n = s.count(old)
    assert n == 1, (old, n)
    s = s.replace(old, new)
for leftover in ('ninjateam', '"FileBird"'):
    assert leftover not in s, leftover

index_old = os.path.basename(index_src)
idx = open(index_src).read()
index_new = 'index-' + hashlib.md5(idx.encode()).hexdigest()[:8] + '.js'
assert s.count('./' + index_old) >= 1
s = s.replace('./' + index_old, './' + index_new)
main_new = 'main-' + hashlib.md5(s.encode()).hexdigest()[:8] + '.js'

for f in glob.glob(os.path.join(dst, 'main-*.js')) + glob.glob(os.path.join(dst, 'index-*.js')):
    os.remove(f)
open(os.path.join(dst, main_new), 'w').write(s)
shutil.copy(index_src, os.path.join(dst, index_new))
shutil.copy(os.path.join(src, 'style.css'), os.path.join(dst, 'style.css'))
manifest = {
    '_' + index_new: {'file': index_new},
    'main.tsx': {'file': main_new, 'imports': ['_' + index_new], 'isEntry': True, 'src': 'main.tsx'},
    'style.css': {'file': 'style.css', 'src': 'style.css'},
}
open(os.path.join(dst, 'manifest.json'), 'w').write(json.dumps(manifest, indent=2) + '\n')
print(main_new, index_new)
