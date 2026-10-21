#!/usr/bin/env python3
"""
File: tests/apiparity/prod_rehearsal_check.py
Description: READ-ONLY comparison of the live strabo-php container (A) with a PHP 8.4 rehearsal
             container (B) on the prod host, against the same prod data. GET requests and exports
             only, as one real account (prompted, never stored). Compares status, content type and
             body: JSON normalized and compared exactly, HTML checked for PHP errors and size,
             downloads compared by file type and size. Nothing is written to the databases.
             Usage (on the prod host):
               python3 prod_rehearsal_check.py [A_BASE] [B_BASE]
               defaults: A = http://127.0.0.1:8086 (live), B = http://127.0.0.1:8186 (rehearsal)
@package    StraboSpot Web Site
@copyright  2026 StraboSpot
@license    https://opensource.org/licenses/MIT MIT License
"""

import base64
import getpass
import http.cookiejar
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

A = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8086"
B = sys.argv[2] if len(sys.argv) > 2 else "http://127.0.0.1:8186"
EMAIL = input("StraboSpot email: ").strip()
PASSWORD = getpass.getpass("Password (not stored): ")
BASIC = "Basic " + base64.b64encode((EMAIL + ":" + PASSWORD).encode()).decode()
PHP_ERROR = re.compile(rb"(Fatal error|Uncaught|TypeError|Parse error|Warning</b>:|Notice</b>:)")

results = []


class NoRedirect(urllib.request.HTTPRedirectHandler):
	def redirect_request(self, *args, **kwargs):
		return None


def opener(jar=None, follow=True):
	handlers = [urllib.request.HTTPCookieProcessor(jar)] if jar is not None else []
	if not follow:
		handlers.append(NoRedirect())
	return urllib.request.build_opener(*handlers)


def fetch(base, path, basic=False, jar=None, data=None, follow=True):
	req = urllib.request.Request(base + path, data=data)
	if basic:
		req.add_header("Authorization", BASIC)
	try:
		with opener(jar, follow).open(req, timeout=600) as r:
			return r.status, r.headers.get("Content-Type", ""), r.read()
	except urllib.error.HTTPError as e:
		return e.code, e.headers.get("Content-Type", ""), e.read()
	except Exception as e:
		return -1, "", str(e).encode()


def norm_json(body):
	try:
		return json.dumps(json.loads(body), sort_keys=True)
	except Exception:
		return None


def compare(label, a, b):
	(sa, ta, ba), (sb, tb, bb) = a, b
	problems = []
	if sa != sb:
		problems.append("status %s vs %s" % (sa, sb))
	if ta.split(";")[0] != tb.split(";")[0]:
		problems.append("type %s vs %s" % (ta, tb))
	if PHP_ERROR.search(bb) and not PHP_ERROR.search(ba):
		problems.append("PHP error text only on B: %s" % PHP_ERROR.search(bb).group(0).decode())
	ja, jb = norm_json(ba), norm_json(bb)
	if ja is not None or jb is not None:
		if ja != jb:
			problems.append("JSON differs (%d vs %d bytes)" % (len(ba), len(bb)))
	elif "html" in ta or "text" in ta:
		if len(ba) and abs(len(ba) - len(bb)) > max(200, len(ba) * 0.05):
			problems.append("HTML size %d vs %d" % (len(ba), len(bb)))
	else:
		if ba[:4] != bb[:4]:
			problems.append("file type %r vs %r" % (ba[:4], bb[:4]))
		if len(ba) and abs(len(ba) - len(bb)) > len(ba) * 0.15:
			problems.append("file size %d vs %d" % (len(ba), len(bb)))
	results.append((label, problems))
	print(("  OK    " if not problems else "  DIFF  ") + label + ("" if not problems else "  <- " + "; ".join(problems)))


def both(label, path, **kw):
	compare(label, fetch(A, path, **kw), fetch(B, path, **kw))


print("A (live) = %s   B (rehearsal) = %s\n" % (A, B))

print("== public pages")
for p in ["/", "/login", "/search", "/strabosearch/", "/register", "/strabovoice_notes"]:
	both(p, p)

print("\n== StraboField API (/db, Basic auth, GET only)")
status, ctype, body = fetch(A, "/db/myProjects", basic=True)
if status != 200:
	sys.exit("Login to /db failed on A (status %s): check the email and password." % status)
both("/db/myProjects", "/db/myProjects", basic=True)
both("/db/myDatasets", "/db/myDatasets", basic=True)
both("/db/profile", "/db/profile", basic=True)
projects = [p["id"] for p in json.loads(body).get("projects", [])][:5]
dsids = []
for pid in projects:
	both("/db/project/%s" % pid, "/db/project/%s" % pid, basic=True)
	both("/db/projectDatasets/%s" % pid, "/db/projectDatasets/%s" % pid, basic=True)
	s, t, b = fetch(A, "/db/projectDatasets/%s" % pid, basic=True)
	try:
		dsids += [d["id"] for d in json.loads(b).get("datasets", [])]
	except Exception:
		pass
dsids = dsids[:8]
for ds in dsids:
	both("/db/dataset/%s" % ds, "/db/dataset/%s" % ds, basic=True)
	both("/db/datasetSpots/%s" % ds, "/db/datasetSpots/%s" % ds, basic=True)

print("\n== StraboMicro / samples / search APIs")
both("/microdb/myProjects", "/microdb/myProjects", basic=True)
both("/searchdb/facets", "/searchdb/facets")

print("\n== website, logged in (separate session per side)")
jars = {A: http.cookiejar.CookieJar(), B: http.cookiejar.CookieJar()}
form = urllib.parse.urlencode({"email": EMAIL, "password": PASSWORD, "submit_login": "Login"}).encode()
for base in (A, B):
	fetch(base, "/login", jar=jars[base])
	fetch(base, "/login", jar=jars[base], data=form, follow=False)


def both_web(label, path):
	compare(label, fetch(A, path, jar=jars[A]), fetch(B, path, jar=jars[B]))


for p in ["/my_data", "/my_field_data", "/my_micro_data", "/my_experimental_data", "/my_samples"]:
	both_web(p, p)
for ds in dsids[:3]:
	for t in ["xls", "kml", "shapefile", "gpkg", "stereonet", "sample_list"]:
		both_web("export %s %s" % (t, ds), "/searchdownload?type=%s&userpkey=0&dsids=%s" % (t, ds))

bad = [r for r in results if r[1]]
print("\n%d checks, %d differences" % (len(results), len(bad)))
for label, problems in bad:
	print("  " + label + ": " + "; ".join(problems))
