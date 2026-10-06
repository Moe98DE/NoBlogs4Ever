#!/usr/bin/env python3
import os,pathlib,re,sys
root=pathlib.Path(__file__).resolve().parents[1]
violations=[]
ignored={'node_modules','.git','secrets','.runtime','vendor','test-results','__pycache__'}
allowed={'.php','.py','.js','.mjs','.json','.md','.yaml','.yml','.html','.sh',''}
for current,dirs,files in os.walk(root):
 dirs[:]=[d for d in dirs if d not in ignored]
 for name in files:
  p=pathlib.Path(current)/name
  if p.suffix not in allowed: continue
  text=p.read_text(errors='replace')
  for pattern in [r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----\s+[A-Za-z0-9+/]{30}',r'AKIA[0-9A-Z]{16}',r'ghp_[A-Za-z0-9]{30,}']:
   if re.search(pattern,text): violations.append(str(p.relative_to(root)))
if violations:print('Possible embedded secrets in: '+', '.join(violations));sys.exit(1)
print('PASS source secret-pattern scan (heuristic, not a complete proof)')
