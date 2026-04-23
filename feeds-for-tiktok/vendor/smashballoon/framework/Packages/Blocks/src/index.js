import { registerBlockType } from '@wordpress/blocks';
import { recommendedBlocks } from './blocks.js';
import Edit from './edit.js';

/**
 * Register the recommended blocks.
 */
recommendedBlocks.forEach((block) => {
    const blockName = 'am-recommended-blocks/' + block.name;

    if (recommendedBlocksData.plugins.includes(block.pluginPath) ||
        recommendedBlocksData.plugins.includes(block.proPluginPath) ||
        wp.blocks.getBlockType(blockName)) {
        return;
    }

    registerBlockType(blockName, {
        title: block.title,
        description: block.description,
        icon: 'admin-plugins',
        category: 'widgets',
        keywords: block.keywords,
        attributes: {
            pluginPath: {
                type: 'string',
                default: block.pluginPath,
            },
            pluginName: {
                type: 'string',
                default: block.title,
            },
            pluginPage: {
                type: 'string',
                default: block.pluginPage,
            },
            logo: {
                type: 'string',
                default: block.logo,
            },
            description: {
                type: 'string',
                default: block.pluginDescription,
            },
            preview: {
                type: 'boolean',
                default: false,
            }
        },
        example: {
            attributes: {
                preview: true,
            }
        },
        edit: Edit,
        save: function () {
            return (<></>);
        },
    });
});