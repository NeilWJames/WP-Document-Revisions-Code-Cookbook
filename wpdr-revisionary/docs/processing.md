# Integration Processing

The primary objective is to allow PublishPress Revisions to operate using its normal processing from the time it creates a revision, but at the time that a revision is reintegrated into the original document then it is done so using rules appropriate to WP Document Revisions processing.

This has several implications identified below.

## Structure of a WP Document Revisions Document

A WP Document Revisions document consists of:
- A custom post of type Document. Its post content contains text linking to the Attachment post and may contain a description of the document.
- An Attachment post linking to the actual document file and has the Document post as its parent.

If a new version of the file is uploaded then this will provoke a new Attachment post to be created together with a Revision post.

Over time, the Document post can be the parent to a number of Attachment and Revision records.

## Terminology

The plugin WP Document Revisions will be referred to as WPDR.

The plugin PublishPress Revisions will be referred to as PPR.

Use of the terms here can be somewhat confusing, So these terms will be used:

|      Term     |                     What it is                        |
|---------------| ------------------------------------------------------|
| WPDR Document | A post created using WP Document Revisions            |
|               | This can have a number of revision posts.             |
| PPR Document  | When a New Revision is created from a WPDR Document   |
|               | it is created as another WPDR Document (see below)    |
|               | When integrated it will become a revision of the      |
|               | WPDR Document. Prior to that it can have revisions.   |

## Creation of a PublishPress Revision

PublishPress Revisions puts a button on the Document Edit screen that will create a Revision.

When a Revision is created, it is not a revision in the sense of a WordPress revision post, but it is a new Document post. It is differentiated from a standard Document in that it has some specific metadata. The post content contains the text linking to the Attachment post, but, of course, the attachment's parent is the original Document.

This would appear as an Invalid document in WP Document Revisions validation processing. Measures are taken to exclude it from this list.

PublishPress Revisions permits multiple Revisions of a post to be present at the same time. This is managed by a user setting (under New Revisions) that can restrict its functionality to one active revision per post.

This can be very confusing with revision being held for the published document and also with PPR documents. Thus the integration plugin will override its value for Documents to allow only one active PPR Document for a WPDR Document. 

## Subsequent Update to the PPR Document prior to integration

Updates can be made using a variation of the Document management screens. New versions of the document file may be loaded as Attachments and will have the PPR Document as their parent and also have revision posts created for it as per normal WPDR processing. 

PublishPress Revisions permits the revision history of the revision process to be retained on integration. Since the revision history is part of the public record it would be confusing if a number of revisions would suddenly appear in the list some of which were never available publicly (but representing the editing process). Thus this integration plugin will delete any PPR Document revisions found and integrate only the PPR Document and the Attachment record associated with it.

Since any PPR Document revisions will be deleted, measures are taken to reduce their creation. Normally a revision is created if there is a change to the title, content or excerpt fields. For a PPR Document, only a change to the content field will cause a revision to be created. The excerpt field should be used to maintain an audit history of changes made to the Document.

## Update to the WPDR Document

Updates can be made to the WPDR Document whilst a PPR Document exists, i.e. prior to its integration. A warning notice is shown on the edit screen when there is one.

## Integration of the PPR Document

This can occur in two circumstances:
1. The PPR Document is approved online. It may have been Submitted previously.
2. The PPR Document is approved online and given a scheduled date; and time passes.

The PublishPress Revisions publish process is the triggered using its process to integrate the PPR Document into the WPDR Document by converting the PPR Document as a revision of the WPDR Document.

## Navigation

The standard process is to display the PPR Document text after submission or approval.

However, this leaves the user with the only option to go back to the edit screen. This is now a revision (or may have been moved) and is not valid to be edited.

So the user will be sent to either the edit page of the WPDR Document or to the PP New Revisions page.
 