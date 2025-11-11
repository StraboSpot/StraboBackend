#!/usr/bin/env python3
"""
Script to convert unsafe SQL database calls to safe prepared statement versions.
This script converts:
- $db->get_row() to $db->get_row_prepared()
- $db->get_var() to $db->get_var_prepared()
- $db->get_results() to $db->get_results_prepared()
- $db->query() to $db->prepare_query()
"""

import re
import sys
import os
from pathlib import Path

def extract_variables_from_query(query_str):
    """
    Extract PHP variables from a SQL query string.
    Returns a tuple of (cleaned_query, params_array, param_count)
    """
    # Remove the opening and closing quotes
    query_str = query_str.strip()

    # Pattern to match PHP variables like $var, $obj->prop, $array['key']
    var_pattern = r'\$[a-zA-Z_][a-zA-Z0-9_]*(?:(?:\->|::)[a-zA-Z_][a-zA-Z0-9_]*|\[[\'"]?[a-zA-Z0-9_]+[\'"]?\])*'

    # Find all variables in the query
    variables = []
    matches = list(re.finditer(var_pattern, query_str))

    if not matches:
        # No variables found, return as is
        return query_str, "array()", 0

    # Replace variables with PostgreSQL placeholders ($1, $2, etc.)
    new_query = query_str
    offset = 0
    param_num = 1

    for match in matches:
        var = match.group(0)
        start = match.start() + offset
        end = match.end() + offset

        # Calculate position adjustment
        placeholder = f"${param_num}"
        old_len = len(var)
        new_len = len(placeholder)

        # Replace in the query
        new_query = new_query[:start] + placeholder + new_query[end:]
        offset += new_len - old_len

        variables.append(var)
        param_num += 1

    # Build the params array
    params_str = "array(" + ", ".join(variables) + ")"

    return new_query, params_str, len(variables)


def convert_get_row(line):
    """Convert $db->get_row() to $db->get_row_prepared()"""
    # Match: $db->get_row("query")
    # or: $db->get_row('query')
    # or: $db->get_row("query", OBJECT)

    pattern = r'\$db->get_row\((["\'])(.+?)\1(?:,\s*\w+)?(?:,\s*\d+)?\)'

    matches = list(re.finditer(pattern, line))
    if not matches:
        return line, False

    converted = False
    for match in matches:
        quote = match.group(1)
        query = match.group(2)

        # Extract variables and convert to prepared statement
        new_query, params, param_count = extract_variables_from_query(query)

        if param_count > 0:
            # Replace the entire function call
            old_call = match.group(0)
            new_call = f'$db->get_row_prepared({quote}{new_query}{quote}, {params})'
            line = line.replace(old_call, new_call)
            converted = True

    return line, converted


def convert_get_var(line):
    """Convert $db->get_var() to $db->get_var_prepared()"""
    pattern = r'\$db->get_var\((["\'])(.+?)\1(?:,\s*\d+)?(?:,\s*\d+)?\)'

    matches = list(re.finditer(pattern, line))
    if not matches:
        return line, False

    converted = False
    for match in matches:
        quote = match.group(1)
        query = match.group(2)

        # Extract variables and convert to prepared statement
        new_query, params, param_count = extract_variables_from_query(query)

        if param_count > 0:
            old_call = match.group(0)
            new_call = f'$db->get_var_prepared({quote}{new_query}{quote}, {params})'
            line = line.replace(old_call, new_call)
            converted = True

    return line, converted


def convert_get_results(line):
    """Convert $db->get_results() to $db->get_results_prepared()"""
    pattern = r'\$db->get_results\((["\'])(.+?)\1(?:,\s*\w+)?\)'

    matches = list(re.finditer(pattern, line))
    if not matches:
        return line, False

    converted = False
    for match in matches:
        quote = match.group(1)
        query = match.group(2)

        # Extract variables and convert to prepared statement
        new_query, params, param_count = extract_variables_from_query(query)

        if param_count > 0:
            old_call = match.group(0)
            new_call = f'$db->get_results_prepared({quote}{new_query}{quote}, {params})'
            line = line.replace(old_call, new_call)
            converted = True

    return line, converted


def convert_query(line):
    """Convert $db->query() to $db->prepare_query()"""
    # Only convert INSERT, UPDATE, DELETE queries with variables
    pattern = r'\$db->query\((["\'])(.+?)\1\)'

    matches = list(re.finditer(pattern, line))
    if not matches:
        return line, False

    converted = False
    for match in matches:
        quote = match.group(1)
        query = match.group(2)

        # Check if it's an INSERT, UPDATE, or DELETE query
        query_lower = query.lower().strip()
        if not (query_lower.startswith('insert') or
                query_lower.startswith('update') or
                query_lower.startswith('delete')):
            continue

        # Extract variables and convert to prepared statement
        new_query, params, param_count = extract_variables_from_query(query)

        if param_count > 0:
            old_call = match.group(0)
            new_call = f'$db->prepare_query({quote}{new_query}{quote}, {params})'
            line = line.replace(old_call, new_call)
            converted = True

    return line, converted


def process_file(file_path, dry_run=False):
    """Process a single PHP file and convert unsafe SQL calls"""
    try:
        with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
            lines = f.readlines()
    except Exception as e:
        print(f"Error reading {file_path}: {e}")
        return False

    new_lines = []
    file_modified = False

    for line in lines:
        original = line

        # Try each conversion
        line, converted1 = convert_get_row(line)
        if converted1:
            file_modified = True

        line, converted2 = convert_get_var(line)
        if converted2:
            file_modified = True

        line, converted3 = convert_get_results(line)
        if converted3:
            file_modified = True

        line, converted4 = convert_query(line)
        if converted4:
            file_modified = True

        new_lines.append(line)

    if file_modified and not dry_run:
        try:
            with open(file_path, 'w', encoding='utf-8') as f:
                f.writelines(new_lines)
            print(f"✓ Converted: {file_path}")
            return True
        except Exception as e:
            print(f"Error writing {file_path}: {e}")
            return False
    elif file_modified and dry_run:
        print(f"Would convert: {file_path}")
        return True

    return False


def find_php_files(root_dir):
    """Find all PHP files in the directory tree"""
    php_files = []
    for root, dirs, files in os.walk(root_dir):
        # Skip vendor, node_modules, etc.
        dirs[:] = [d for d in dirs if d not in ['vendor', 'node_modules', '.git']]

        for file in files:
            if file.endswith('.php'):
                php_files.append(os.path.join(root, file))

    return php_files


def main():
    if len(sys.argv) < 2:
        print("Usage: python3 convert_to_safe_sql.py <file_or_directory> [--dry-run]")
        sys.exit(1)

    path = sys.argv[1]
    dry_run = '--dry-run' in sys.argv

    if not os.path.exists(path):
        print(f"Error: {path} does not exist")
        sys.exit(1)

    # Handle both files and directories
    if os.path.isfile(path):
        php_files = [path]
    elif os.path.isdir(path):
        print(f"Scanning for PHP files in {path}...")
        php_files = find_php_files(path)
        print(f"Found {len(php_files)} PHP files")
    else:
        print(f"Error: {path} is neither a file nor a directory")
        sys.exit(1)

    if dry_run:
        print("\n=== DRY RUN MODE - No files will be modified ===\n")

    converted_count = 0
    for php_file in php_files:
        if process_file(php_file, dry_run):
            converted_count += 1

    print(f"\n{'Would convert' if dry_run else 'Converted'} {converted_count} files")


if __name__ == '__main__':
    main()
