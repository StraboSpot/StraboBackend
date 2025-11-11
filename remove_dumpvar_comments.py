#!/usr/bin/env python3
"""
Script to remove comment lines containing 'dumpVar' references from PHP files.
Only removes single-line comments (// style) to avoid breaking multi-line comments.
"""

import os
import re
import sys

def should_remove_line(line):
    """Check if a line is a comment containing dumpVar reference."""
    stripped = line.strip()

    # Check if it's a single-line comment containing dumpVar (case-insensitive)
    if stripped.startswith('//'):
        if re.search(r'dumpvar', line, re.IGNORECASE):
            return True

    return False

def process_php_file(filepath):
    """Process a single PHP file to remove dumpVar comment lines."""
    try:
        with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
            lines = f.readlines()

        # Filter out lines containing dumpVar in comments
        new_lines = []
        removed_count = 0

        for line in lines:
            if should_remove_line(line):
                removed_count += 1
                print(f"  Removing: {line.rstrip()}")
            else:
                new_lines.append(line)

        # Only write if we actually removed something
        if removed_count > 0:
            with open(filepath, 'w', encoding='utf-8') as f:
                f.writelines(new_lines)
            print(f"✓ {filepath}: Removed {removed_count} comment line(s)")
            return removed_count

        return 0

    except Exception as e:
        print(f"✗ Error processing {filepath}: {e}", file=sys.stderr)
        return 0

def find_and_process_php_files(root_dir):
    """Recursively find and process all PHP files."""
    total_files_modified = 0
    total_lines_removed = 0

    for dirpath, dirnames, filenames in os.walk(root_dir):
        # Skip hidden directories and common excluded directories
        dirnames[:] = [d for d in dirnames if not d.startswith('.') and d not in ['node_modules', 'vendor']]

        for filename in filenames:
            if filename.endswith('.php'):
                filepath = os.path.join(dirpath, filename)
                removed = process_php_file(filepath)
                if removed > 0:
                    total_files_modified += 1
                    total_lines_removed += removed

    return total_files_modified, total_lines_removed

if __name__ == '__main__':
    root_directory = os.getcwd()
    print(f"Scanning for PHP files in: {root_directory}")
    print(f"Looking for comment lines containing 'dumpVar' references...\n")

    files_modified, lines_removed = find_and_process_php_files(root_directory)

    print(f"\n{'='*60}")
    print(f"Summary:")
    print(f"  Files modified: {files_modified}")
    print(f"  Comment lines removed: {lines_removed}")
    print(f"{'='*60}")
