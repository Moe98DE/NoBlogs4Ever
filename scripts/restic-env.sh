#!/usr/bin/env sh
# Import backend credentials without evaluating shell syntax or printing values.
load_restic_backend_env() {
  file=${RESTIC_BACKEND_ENV_FILE:-/run/secrets/restic_backend_env}
  [ -r "$file" ] || { echo "Restic backend credential file is not readable." >&2; return 1; }
  while IFS= read -r line || [ -n "$line" ]; do
    line=$(printf '%s' "$line" | tr -d '\r')
    case "$line" in ''|'#'*) continue ;; esac
    key=${line%%=*}
    value=${line#*=}
    case "$key" in
      RESTIC_REST_USERNAME|RESTIC_REST_PASSWORD|AWS_ACCESS_KEY_ID|AWS_SECRET_ACCESS_KEY|AWS_SESSION_TOKEN|AWS_DEFAULT_REGION|B2_ACCOUNT_ID|B2_ACCOUNT_KEY|AZURE_ACCOUNT_NAME|AZURE_ACCOUNT_KEY|GOOGLE_PROJECT_ID|GOOGLE_APPLICATION_CREDENTIALS|RCLONE_CONFIG|RCLONE_PASSWORD_COMMAND|OS_AUTH_URL|OS_USERNAME|OS_PASSWORD|OS_PROJECT_NAME|OS_REGION_NAME) ;;
      *) echo "Unsupported key in Restic backend credential file: $key" >&2; return 1 ;;
    esac
    export "$key=$value"
  done < "$file"
}
