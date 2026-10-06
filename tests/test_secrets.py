import pathlib,shutil,subprocess,tempfile,unittest,stat,sys,os
class SecretGeneration(unittest.TestCase):
 def test_idempotent_private_secrets(self):
  with tempfile.TemporaryDirectory() as tmp:
   root=pathlib.Path(tmp);(root/'scripts').mkdir()
   src=pathlib.Path(__file__).parents[1]/'scripts/generate-secrets.py'
   shutil.copy(src,root/'scripts/generate-secrets.py')
   subprocess.run([sys.executable,str(root/'scripts/generate-secrets.py')],check=True,capture_output=True)
   files=list((root/'secrets').iterdir());self.assertEqual(len(files),8)
   before={p.name:p.read_text() for p in files}
   self.assertEqual(len(set(before.values())),8)
   for p in files:
    if os.name == 'posix':
     self.assertEqual(stat.S_IMODE(p.stat().st_mode),0o600)
    self.assertGreaterEqual(len(p.read_text()),30)
   subprocess.run([sys.executable,str(root/'scripts/generate-secrets.py')],check=True,capture_output=True)
   self.assertEqual(before,{p.name:p.read_text() for p in files})
if __name__=='__main__':unittest.main()
