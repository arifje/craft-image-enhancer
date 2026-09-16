# Release workflow

- Always publish a release tag when pushing changes to this repository.
- Before committing a release, update the version in `composer.json`. Use a patch increment for fixes and a minor increment for new features, following the existing release history.
- Create an annotated tag matching the Composer version, without a `v` prefix (for example, `1.19.1`), on the release commit.
- Push the branch and its new tag together, preferably with `git push --atomic`, and verify both reached the remote.
- Never move or overwrite an existing release tag.
