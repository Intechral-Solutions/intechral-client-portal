#!/usr/bin/env bash
# Open (decrypt) one sealed backup set on the owner's machine (Release 0.9 WP-B1).
#
#   open-backup-set.sh <sealed-set-dir> <new-output-dir> [--expect-digest <sha256>]
#
# Runs where the owner's private key is (it uses the caller's gpg keyring and agent; the key never goes anywhere).
# It needs only bash, coreutils and gpg. Fails closed, in this order, before anything is decrypted:
#   - the set is a directory named by a backup id, holding exactly the six sealed files, all regular files;
#   - COMPLETE names this format and id, and the SHA-256 of SHA256SUMS (the set digest), which must also equal
#     --expect-digest when given (the digest recorded when the set was made: that is what authenticates it);
#   - SHA256SUMS lists exactly set.json and the three .gpg artifacts, and every one matches.
# Then each artifact is decrypted into a private temporary directory beside the output; gpg must report a
# good, integrity-protected decryption. Only when all three succeed is it renamed to <new-output-dir>, with copies
# of set.json, SHA256SUMS and COMPLETE. On any failure the temporary directory is removed and nothing is left.
#
# The output is PLAINTEXT customer data (mode 0700/0600). Next: `php artisan backup:verify <dir>` and
# `php artisan backup:drill <dir> ...`; delete it when finished (docs/operations/backup-and-recovery.md).
#
# Exit codes: 0 opened; 1 refused or failed (nothing written).
set -euo pipefail
umask 077

die() { printf 'open-backup-set: %s\n' "$*" >&2; exit 1; }

[[ $# -eq 2 || ( $# -eq 4 && $3 == --expect-digest ) ]] || die "usage: open-backup-set.sh <sealed-set-dir> <new-output-dir> [--expect-digest <sha256>]"
set_dir=${1%/}
out=${2%/}
expect=${4:-}
format='intechral-portal-backup/1'
artifacts=(manifest.json database.sql.gz attachments.tar.gz)

command -v gpg >/dev/null || die "gpg is not installed"
[[ -d $set_dir && ! -L $set_dir ]] || die "$set_dir is not a directory"
id=$(basename -- "$set_dir")
[[ $id =~ ^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}$ ]] || die "$id is not a backup set id"
[[ -z $expect || $expect =~ ^[0-9a-f]{64}$ ]] || die "--expect-digest must be 64 lowercase hexadecimal characters"

# Exactly the sealed files, all regular files.
expected_files="COMPLETE SHA256SUMS attachments.tar.gz.gpg database.sql.gz.gpg manifest.json.gpg set.json"
present=$(cd -- "$set_dir" && LC_ALL=C ls -A | tr '\n' ' ' | sed 's/ $//')
[[ $present == "$expected_files" ]] || die "the set does not hold exactly: $expected_files (incomplete or altered)"
for f in $expected_files; do
    [[ -f $set_dir/$f && ! -L $set_dir/$f ]] || die "$f is not a regular file"
done

# COMPLETE -> SHA256SUMS -> every file.
digest=$(sha256sum -- "$set_dir/SHA256SUMS" | cut -d' ' -f1)
complete="format=$format
backup_id=$id
sha256sums=$digest"
[[ $(cat -- "$set_dir/COMPLETE") == "$complete" ]] || die "COMPLETE does not match this set (never completed, or altered)"
[[ -z $expect || $expect == "$digest" ]] || die "the set digest is $digest, not the recorded $expect: this is not the set that was made"
listed=$(cut -c67- -- "$set_dir/SHA256SUMS" | LC_ALL=C sort | tr '\n' ' ' | sed 's/ $//')
[[ $listed == "attachments.tar.gz.gpg database.sql.gz.gpg manifest.json.gpg set.json" ]] || die "SHA256SUMS does not list exactly the set's files"
grep -Eqv '^[0-9a-f]{64}  [A-Za-z0-9._]+$' -- "$set_dir/SHA256SUMS" && die "SHA256SUMS has a malformed line"
(cd -- "$set_dir" && sha256sum --check --strict --quiet SHA256SUMS) || die "a file does not match SHA256SUMS (corrupt or replaced)"

# Decrypt into a private temporary directory, then publish it whole.
[[ ! -e $out && ! -L $out ]] || die "$out already exists; choose a new directory"
parent=$(dirname -- "$out")
[[ -d $parent ]] || die "$parent does not exist"
work=$(mktemp -d -- "$parent/.open-$id.XXXXXX")
trap 'rm -rf -- "$work"' EXIT
chmod 700 -- "$work"

for a in "${artifacts[@]}"; do
    status=$(gpg --batch --yes --status-fd 1 --output "$work/$a" --decrypt "$set_dir/$a.gpg" 2>"$work/.gpg-stderr") \
        || die "decryption of $a.gpg failed: $(tail -n 3 -- "$work/.gpg-stderr" | tr '\n' ' ')(wrong or unavailable key?)"
    grep -q '^\[GNUPG:\] DECRYPTION_OKAY' <<<"$status" || die "gpg did not report a good decryption of $a.gpg"
    grep -q '^\[GNUPG:\] DECRYPTION_FAILED' <<<"$status" && die "gpg reported a failed decryption of $a.gpg"
done
rm -f -- "$work/.gpg-stderr"

cp -- "$set_dir/set.json" "$set_dir/SHA256SUMS" "$set_dir/COMPLETE" "$work/"
chmod 600 -- "$work"/*
mv -T -- "$work" "$out"
trap - EXIT

printf 'Opened set %s into %s (digest %s).\n' "$id" "$out" "$digest"
[[ -n $expect ]] || printf 'WARNING: opened WITHOUT --expect-digest: the set is intact but NOT authenticated (anyone able to write it could have resealed it).\n'
printf 'It is DECRYPTED customer data and is not yet verified: run backup:verify and backup:drill on it, then delete it.\n'
