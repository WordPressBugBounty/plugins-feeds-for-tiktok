import React, { Fragment } from "react";
import { __ } from "@wordpress/i18n";
import { PlusIcon } from "../icons";
import { Button, SelectControl } from '@wordpress/components';
import {CreateFeedUpsell, ProUpsell} from './upsells';
import {smashBlocks} from './blocks';

const FeedCTA = (props) => {
    const { id, feeds, feedsOptions, createFeedUrl, isPro, attributes, setAttributes } = props;
    const { feedId } = attributes;
    const block = smashBlocks.find((item) => item.id === id);
    const upsellDesc = isPro ? block?.upsellDesc : block?.upsellDescFree;

    return (
        <Fragment>
            <div className='sbi-feed-blocks-cta-wrap'>
                <div className='sbi-feed-blocks-cta'>
                    {block?.logo}

                    {feeds && feeds.length === 0 && (
                        <div className="sbi-feed-blocks-get-started">
                            <h4>
                                {block?.getStarted}
                            </h4>
                            <Button
                                isPrimary
                                href={createFeedUrl || '#'}
                                target="_blank"
                                rel="noopener noreferrer"
                                icon={PlusIcon}
                                style={{background: '#0068A0', color: '#fff'}}
                            >
                                {block?.createFeed}
                            </Button>
                        </div>
                    )}

                    {!feedId && feeds && feeds.length > 0 && (
                        <Fragment>
                            <h4>
                                {block?.selectFeed}
                            </h4>
                            <SelectControl
                                value={ feedId }
                                options={ feedsOptions || [] }
                                onChange={ ( feedId ) => setAttributes( { feedId } ) }
                                __nextHasNoMarginBottom = {true}
                                style={{fontSize: '14px'}}
                            />
                            <p>
                                <CreateFeedUpsell 
                                    orCreateFeed ={block?.orCreateFeed}
                                />
                            </p>
                        </Fragment>
                    )}
                </div>
                <div className='sbi-feed-blocks-upsell'>
                    <div className="sbi-feed-blocks-info">
                        <strong>
                            {block?.upsellTitle}
                        </strong>
                        <p>
                            <ProUpsell
                                upsellDesc={upsellDesc}
                                id={id}
                            />
                        </p>
                    </div>
                </div>
            </div>
        </Fragment>
    )
}

export default FeedCTA;