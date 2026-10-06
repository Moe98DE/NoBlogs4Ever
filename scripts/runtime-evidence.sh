#!/usr/bin/env bash
set -euo pipefail
root=$(CDPATH='' cd -- "$(dirname "$0")/.." && pwd)
compose_command=${COMPOSE:-docker compose}
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
cd "$root"
[ "${NBE_SKIP_BUILD:-no}" = yes ] || $compose_command build app
$compose_command up -d db app worker
$compose_command run --rm cli wp eval-file /opt/nbe/scripts/collect-versions.php --allow-root > "$tmp/runtime.json"
app_id=$($compose_command images -q app | head -1)
db_id=$($compose_command images -q db | head -1)
test -n "$app_id" && test -n "$db_id"
candidate="$tmp/candidate.json"
NBE_ROOT="$root" APP_IMAGE_ID="$app_id" DB_IMAGE_ID="$db_id" php -r '
$path=$argv[1]; $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
$data["source"]="production-compose-images";
$data["application_image_id"]=getenv("APP_IMAGE_ID");
$data["database_image_id"]=getenv("DB_IMAGE_ID");
preg_match("/^FROM (\\S+)/m", file_get_contents(getenv("NBE_ROOT")."/Dockerfile"), $m); $data["dockerfile_base"]=$m[1];
$data["compose_database"]="mariadb:11.8@sha256:2439dcd7d14010ecd1ff7a4e1c5abe8e208c34fe35290744deeeaac3569043c3";
$data["capture_status"]="full-runtime";
file_put_contents($argv[2],json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
' "$tmp/runtime.json" "$candidate"
if [ "${1:-}" = --verify ]; then
  php -r '
  $recorded=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
  $actual=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
  foreach(["wordpress","php","database","plugins","themes","multisite","subdomains"] as $key){if(($recorded[$key]??null)!==($actual[$key]??null)){fwrite(STDERR,"Runtime evidence drift: $key\n");exit(1);}}
  if(($recorded["capture_status"]??"")!=="full-runtime"){fwrite(STDERR,"Recorded evidence is not a full runtime capture.\n");exit(1);}
  ' "$root/docs/evidence/runtime-versions.json" "$candidate"
  echo 'Runtime evidence matches the actual application and database images.'
else
  mv "$candidate" "$root/docs/evidence/runtime-versions.json"
  echo "Wrote docs/evidence/runtime-versions.json from application $app_id and database $db_id"
fi
