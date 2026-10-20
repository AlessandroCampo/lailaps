from pathlib import Path
import difflib
root=Path('/home/alessandro/projects/lailaps-2')
base=Path('/mnt/c/Users/Alessandro/Desktop/latest-projects/lailaps/work/ui-implementation-20261018/before')
patch=[]
for area in ['app','resources','routes','database/migrations','docs']:
 old=base/area; new=root/area
 paths=sorted({p.relative_to(old) for p in old.rglob('*') if p.is_file()} | {p.relative_to(new) for p in new.rglob('*') if p.is_file()})
 for p in paths:
  a=old/p; b=new/p
  aa=a.read_text(errors='replace') if a.exists() else ''
  bb=b.read_text(errors='replace') if b.exists() else ''
  if aa==bb:continue
  if any(part in ['actions','wayfinder','routes'] for part in p.parts) and area=='resources':continue
  diff=list(difflib.unified_diff(aa.splitlines(True),bb.splitlines(True),fromfile='a/'+area+'/'+str(p),tofile='b/'+area+'/'+str(p)))
  patch.extend(diff)
  print(('ADD ' if not a.exists() else 'MOD ')+area+'/'+str(p))
for name in ['ARCHITECTURE.md','package.json','package-lock.json']:
 a=base/name;b=root/name
 aa=a.read_text();bb=b.read_text()
 if aa!=bb:
  print('MOD '+name)
  patch.extend(difflib.unified_diff(aa.splitlines(True),bb.splitlines(True),fromfile='a/'+name,tofile='b/'+name))
(base.parent/'changes.patch').write_text(''.join(patch))
