# Why an architecture graph helps

In [the report example](report), the controller calls `ReportService::run()`, which calls `Gateway::expensive()`. A direct import check on the controller would miss that second hop. The graph reports `scope-forbidden-entrypoint-method` with the modeled path. The same controller also imports `Service\Internal\Hidden`; that produces `forbidden-internal-access` because only files ending in `Service.php` are public service entrypoints in this policy.

Run the example from this repository after `composer install`:

```sh
cd examples/report
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note
```

Look for both findings, their source locations, the `graph-evidence` note and the `analysis-attestation` note. A nonzero exit code is expected because both violations are intentional. The policy combines actual paths and parsed namespaces; a class name or import by itself does not prove a call. Unresolved dynamic dispatch is reported as incomplete rather than treated as allowed.
