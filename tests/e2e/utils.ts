import { execFileSync } from 'node:child_process';

/** Runs WP-CLI inside a site container and returns trimmed stdout. */
export function wp( site: 'blog' | 'shop', ...args: string[] ): string {
	return execFileSync( 'docker', [ 'compose', 'exec', '-T', site, 'wp', '--allow-root', ...args ], { encoding: 'utf8' } ).trim();
}
