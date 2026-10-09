"""The scorer's self-test (a made-up trial with known answers) as a unit test.

Needs openpyxl (requirements-score.txt); skipped where it is not installed
(the worker image does not need it).
"""

import unittest

try:
    import openpyxl  # noqa: F401
    HAVE_XLSX = True
except ImportError:
    HAVE_XLSX = False


@unittest.skipUnless(HAVE_XLSX, 'openpyxl not installed')
class ScoreSelfTest(unittest.TestCase):
    def test_selftest(self):
        from vsworker.score.selftest import run
        self.assertEqual(run(), 0)


if __name__ == '__main__':
    unittest.main()
